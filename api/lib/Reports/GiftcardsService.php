<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Contacts\ContactDisplayName;
use Punto\Api\Support\DbQueryException;

/**
 * Dominio de Reportes — Gift Cards (API compartida, motor ERP).
 *
 * F2 giftcard-issue-flow (2026-07-18): repuntado de la tabla legacy
 * `giftCardSold` a la tabla NUEVA `giftcard` (mig 44 + 78 — emisión desde la
 * venta). `giftCardSold` NO se borra (histórico legado), pero este reporte ya
 * no la lee — las gift cards emitidas antes del cutover no aparecen acá
 * (deuda conocida, no hay migración de datos histórica planeada).
 *
 * Cambios vs la versión anterior (que leía `giftCardSold`):
 *  - Columnas quoted mixed-case (la tabla `giftcard` NO es legacy lowercase-folded).
 *  - `beneficiary` resuelve el nombre ACTUAL del contact; si fue borrado, cae al
 *    snapshot `beneficiaryName` guardado al emitir.
 *  - `code` es string (antes int) — el código ahora es alfanumérico, generado
 *    client-side.
 *  - Sin `color`/`sendDate`/`ucode` (no existen en el nuevo schema; el legacy
 *    los usaba para el flujo de envío por mail, que no se portó).
 *  - `note` es texto plano (la tabla vieja lo guardaba base64; la nueva no).
 *
 * Tenant: companyId + outletId (VIEW_OUTLET_ID override) por parámetro,
 * bindeados con columnas QUOTED (`companyid`, `outletid`) — la tabla
 * `giftcard` usa identificadores mixed-case, a diferencia de `contact`/
 * `transaction`/`outlet` (legacy, lowercase-folded, sin comillas).
 */
final class GiftcardsService
{
    /**
     * Gift cards emitidas. $filters: ['singleRow'=>uuid].
     *
     * @param list<string> $outletIds Alcance por sucursal (`OutletScope::effectiveIds()`);
     *                                `[]` = todas, 2+ = las del usuario.
     *
     * El filtro de sucursal va INTERPOLADO por `OutletScope::sqlFilter()` y el
     * `singleRow` sigue bindeado DESPUÉS: si el alcance ocupara placeholders,
     * pasar de una sucursal a dos correría ese bind y el `id = ?` empezaría a
     * comparar contra un uuid de sucursal.
     */
    public function detail(array $filters, string $companyId, array $outletIds = []): array
    {
        $params = [$companyId];
        $sql    = 'SELECT * FROM giftcard WHERE companyid = ?'
                . \Punto\Api\Outlets\OutletScope::sqlFilter('outletid', $outletIds);
        if (!empty($filters['singleRow'])) {
            $sql .= ' AND id = ?';
            $params[] = $filters['singleRow'];
        }
        $sql .= ' ORDER BY createdat DESC LIMIT 5000';

        $res = ncmExecute($sql, $params, false, false, true);
        $res = is_array($res) ? $res : [];
        if (!$res) {
            return ['rows' => []];
        }

        $benefIds = $outletIds = $txIds = [];
        foreach ($res as $f) {
            $benefIds[]  = (string) ($f['beneficiaryContactId'] ?? '');
            $outletIds[] = (string) ($f['outletId'] ?? '');
            $txIds[]     = (string) ($f['issuedByTransactionId'] ?? '');
        }
        $benefs  = ContactDisplayName::batch($benefIds, $companyId);
        $outlets = $this->nameMap('outlet', 'outletId', 'outletName', $outletIds, $companyId);
        $docs    = $this->invoiceDocs($txIds, $companyId);

        $rows = [];
        foreach ($res as $f) {
            $tid     = (string) ($f['issuedByTransactionId'] ?? '');
            $benefId = (string) ($f['beneficiaryContactId'] ?? '');
            $rows[]  = [
                'id'            => (string) $f['id'],
                'transactionId' => $tid,
                'doc'           => $tid !== '' ? ($docs[$tid] ?? '-') : '-',
                'beneficiaryId' => $benefId,
                // Preferimos el nombre ACTUAL del contact; si fue borrado o no
                // hay beneficiario, caemos al snapshot guardado al emitir.
                'beneficiary'   => $benefs[$benefId] ?? (string) ($f['beneficiaryName'] ?? ''),
                'expires'       => (string) ($f['expiresAt'] ?? ''),
                'code'          => (string) ($f['code'] ?? ''),
                'note'          => (string) ($f['note'] ?? ''),
                'lastUsed'      => (string) ($f['usedAt'] ?? ''),
                'outletName'    => $outlets[(string) ($f['outletId'] ?? '')] ?? '',
                'value'         => (float) ($f['currentBalance'] ?? 0),
                'initialValue'  => (float) ($f['initialBalance'] ?? 0),
            ];
        }

        return ['rows' => $rows];
    }

    /** Elimina una gift card scopeada por companyId. */
    public function delete(string $id, string $companyId): bool
    {
        global $db;
        $r = $db->Execute(
            'DELETE FROM giftcard WHERE id = ? AND companyid = ?',
            [$id, $companyId]
        );
        return $r !== false;
    }

    /**
     * Actualiza campos editables de una gift card.
     * $data: [code(string), value(float, → currentBalance), expires(string|null),
     *         note(string), beneficiaryId(string|null)]
     *
     * `value` edita SOLO currentBalance (saldo disponible) — initialBalance
     * queda intacto como registro histórico de lo emitido/vendido.
     *
     * Código único case-insensitive: mismo criterio que
     * SaleService::issueGiftCard() (emisión desde la venta) — el canje
     * (api/v1/giftcards.php validate/consume) matchea con
     * UPPER(code)=UPPER(?), y el índice uq_giftcard_company_code_ci (mig 126)
     * es sobre UPPER(code), así que esta UPDATE (la otra escritura de `code`
     * en el repo, vía panel) normaliza a mayúsculas y pre-chequea unicidad
     * ANTES de tocar la fila — sin esto, editar el código desde el panel
     * podía recrear el mismo bug de plata fantasma que la mig 126 cierra
     * para la emisión.
     *
     * @throws \InvalidArgumentException si el código editado ya existe en
     *         OTRA gift card del mismo tenant (case-insensitive) — el
     *         caller (api/v1/reports/giftcards.php) lo traduce a 422.
     */
    public function update(string $id, array $data, string $companyId): bool
    {
        global $db;

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code !== '') {
            $dup = $db->Execute(
                'SELECT id FROM giftcard
                  WHERE companyid = ? AND UPPER(code) = UPPER(?) AND id != ?
                  LIMIT 1',
                [$companyId, $code, $id]
            );
            if ($dup && !$dup->EOF) {
                throw new \InvalidArgumentException("El código de gift card '{$code}' ya existe — usá otro");
            }
        }

        // beneficiaryContactId SOLO se persiste si el lookup scopeado por
        // companyId lo confirma — si el UUID no existe o pertenece a OTRO
        // tenant, tanto el id como el nombre quedan null (nunca guardamos un
        // contactId ajeno/inexistente, aunque el front lo haya mandado).
        $benefId = (string) ($data['beneficiaryId'] ?? '');
        $beneficiaryName = null;
        if ($benefId !== '') {
            $c = $db->Execute(
                'SELECT contactName FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',
                [$benefId, $companyId]
            );
            if ($c && !$c->EOF) {
                $beneficiaryName = trim((string) ($c->fields['contactname'] ?? '')) ?: null;
            } else {
                $benefId = ''; // no resuelto/foráneo → no persistir el id crudo
            }
        }

        $note = (string) ($data['note'] ?? '');
        $expires = (string) ($data['expires'] ?? '');

        try {
            $r = $db->Execute(
                'UPDATE giftcard
                    SET code = ?, currentbalance = ?, expiresat = ?, note = ?,
                        beneficiarycontactid = ?, beneficiaryname = ?
                  WHERE id = ? AND companyid = ?',
                [
                    $code,
                    (float) ($data['value'] ?? 0),
                    ($expires !== '' ? $expires : null),
                    ($note !== '' ? $note : null),
                    ($benefId !== '' ? $benefId : null),
                    $beneficiaryName,
                    $id,
                    $companyId,
                ]
            );
        } catch (DbQueryException $e) {
            // Carrera concurrente contra uq_giftcard_company_code_ci (mig 126)
            // que el pre-check de arriba no alcanzó a ver — mismo criterio que
            // SaleService::issueGiftCard(). Antes esto se detectaba leyendo
            // `ErrorMsg()` DESPUÉS de que Execute() devolviera false; el wrapper
            // ahora lanza, así que la detección vive en el catch y mira el
            // SQLSTATE exacto además del texto.
            $err = $e->getMessage();
            if ($e->sqlState() === '23505'
                || stripos($err, '23505') !== false
                || stripos($err, 'unique') !== false
                || stripos($err, 'duplicate') !== false) {
                throw new \InvalidArgumentException("El código de gift card '{$code}' ya existe — usá otro");
            }
            // Cualquier otra falla de UPDATE se propaga: antes devolvía `false`
            // y el caller lo mostraba como "no se pudo guardar" sin causa.
            throw $e;
        }
        // `$r === false` solo con el kill-switch DB_THROW_ON_ERROR apagado.
        return $r !== false;
    }

    /** Lookup batch transactionId → "invoicePrefix+invoiceNo", scopeado por companyId. */
    private function invoiceDocs(array $ids, $companyId)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT transactionId, invoiceNo, invoicePrefix FROM transaction WHERE companyId = ? AND transactionId IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $no = (string) ($r['invoiceNo'] ?? '');
            $map[(string) $r['transactionId']] = $no !== '' ? ((string) ($r['invoicePrefix'] ?? '') . $no) : '-';
        }
        return $map;
    }

    /** Lookup batch id→name de outlet, scopeado por companyId. */
    private function nameMap($table, $idCol, $nameCol, array $ids, $companyId)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $res = ncmExecute(
            "SELECT $idCol, $nameCol FROM $table WHERE companyId = ? AND $idCol IN ($ph)",
            array_merge([$companyId], $ids), false, false, true
        );
        $res = is_array($res) ? $res : [];
        $map = [];
        foreach ($res as $r) {
            $map[(string) $r[$idCol]] = (string) ($r[$nameCol] ?? '');
        }
        return $map;
    }
}
