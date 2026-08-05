<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Contacts\ContactDisplayName;

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
 * bindeados con columnas QUOTED (`"companyId"`, `"outletId"`) — la tabla
 * `giftcard` usa identificadores mixed-case, a diferencia de `contact`/
 * `transaction`/`outlet` (legacy, lowercase-folded, sin comillas).
 */
final class GiftcardsService
{
    /** Gift cards emitidas. $filters: ['singleRow'=>uuid]. */
    public function detail(array $filters, string $companyId, string $outletId = ''): array
    {
        $params = [$companyId];
        $sql    = 'SELECT * FROM giftcard WHERE "companyId" = ?';
        if ($outletId !== '') {
            $sql .= ' AND "outletId" = ?';
            $params[] = $outletId;
        }
        if (!empty($filters['singleRow'])) {
            $sql .= ' AND id = ?';
            $params[] = $filters['singleRow'];
        }
        $sql .= ' ORDER BY "createdAt" DESC LIMIT 5000';

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
            'DELETE FROM giftcard WHERE id = ? AND "companyId" = ?',
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
     */
    public function update(string $id, array $data, string $companyId): bool
    {
        global $db;

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

        $r = $db->Execute(
            'UPDATE giftcard
                SET code = ?, "currentBalance" = ?, "expiresAt" = ?, note = ?,
                    "beneficiaryContactId" = ?, "beneficiaryName" = ?
              WHERE id = ? AND "companyId" = ?',
            [
                (string) ($data['code'] ?? ''),
                (float) ($data['value'] ?? 0),
                ($expires !== '' ? $expires : null),
                ($note !== '' ? $note : null),
                ($benefId !== '' ? $benefId : null),
                $beneficiaryName,
                $id,
                $companyId,
            ]
        );
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
