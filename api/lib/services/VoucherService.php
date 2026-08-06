<?php
declare(strict_types=1);
namespace Punto\Api\Services;

/**
 * VoucherService — módulo de Vouchers F1 (context/36-vouchers-plan.md).
 *
 * Un voucher es un vale por PRODUCTOS exactos (no por monto), de UN SOLO USO,
 * vendido en la caja. Dominio aparte de GiftCardService (esa es por importe);
 * no lo reemplaza.
 *
 * Patrón de service: mismo que OrderCoreService/TransactionLinkService —
 * $companyId explícito en cada método público, ncmExecute() para lecturas,
 * StartTrans/HasFailedTrans/CompleteTrans para escrituras con invariantes.
 *
 * Trampas del dominio (ver context/36 y CLAUDE.md — ya costaron deploys):
 *   - ncmExecute() devuelve CaseInsensitiveArray (objeto) en fila única;
 *     `!$row` es la guarda correcta, NUNCA is_array()/(array).
 *   - Con forceObj=true SIEMPRE devuelve RecordsetIterator — se itera con
 *     while (!$rs->EOF); es el único modo seguro para listas de tamaño
 *     variable (con 1 sola fila, sin forceObj, ncmExecute "aplana" a array).
 *   - Identificadores SQL en minúscula sin comillas (mig 100).
 */
final class VoucherService
{
    /** Alfabeto sin 0/O/1/I — dictable por teléfono, mismo criterio que giftcard. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const CODE_PREFIX   = 'VC-';
    private const MAX_CODE_ATTEMPTS = 5;

    /**
     * Emite un voucher con sus ítems, congelando el precio vigente de cada
     * uno (`unitpriceatissue`). Atómico: cabecera + ítems en una sola
     * transacción — si falla algo, no queda un voucher a medias.
     *
     * @param array<int, array{itemId: string, qty: float|int|string}> $items
     * @return array{voucherId: string, code: string, items: array<int, array>}
     */
    public function issue(
        string $companyId,
        string $outletId,
        array $items,
        string $transactionId,
        ?string $expiresAt = null,
        ?string $beneficiaryContactId = null
    ): array {
        global $db;

        if ($outletId === '') {
            throw new \InvalidArgumentException('outletId requerido');
        }
        if ($transactionId === '') {
            throw new \InvalidArgumentException('transactionId requerido');
        }
        if (empty($items)) {
            throw new \InvalidArgumentException('items requerido (al menos un ítem)');
        }

        $db->StartTrans();

        // Código único por company — reintenta ante colisión (poco probable
        // con 8 chars de un alfabeto de 32, pero la unicidad es del negocio,
        // no un accidente: dos vouchers con el mismo código son ambiguos).
        $code = null;
        for ($attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++) {
            $candidate = $this->generateCode();
            $exists = ncmExecute(
                'SELECT 1 FROM voucher WHERE companyid = ? AND code = ? LIMIT 1',
                [$companyId, $candidate]
            );
            if (!$exists) {
                $code = $candidate;
                break;
            }
        }
        if ($code === null) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo generar un código único para el voucher');
        }

        // beneficiaryContactId es opcional pero, si viene, tiene que ser un
        // contacto de ESTE tenant — la FK sola lo rechazaría con un error de
        // integridad genérico (contacto de otro company existe en la tabla,
        // solo no matchea el companyid), no un 422 legible.
        if ($beneficiaryContactId !== null) {
            $contactRow = ncmExecute(
                'SELECT 1 FROM contact WHERE contactid = ? AND companyid = ? LIMIT 1',
                [$beneficiaryContactId, $companyId]
            );
            if (!$contactRow) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \InvalidArgumentException('beneficiaryContactId inválido para este tenant');
            }
        }

        $rs = $db->Execute(
            'INSERT INTO voucher
                (voucherid, companyid, outletid, code, status, expiresat, issuedbytransactionid, beneficiarycontactid)
             VALUES (gen_random_uuid(), ?, ?, ?, 1, ?, ?, ?)
             RETURNING voucherid',
            [$companyId, $outletId, $code, $expiresAt, $transactionId, $beneficiaryContactId]
        );
        if ($rs === false || $rs->EOF) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear el voucher');
        }
        $voucherId = (string) $rs->fields['voucherid'];

        $lines = [];
        foreach ($items as $item) {
            $itemId = isset($item['itemId']) ? (string) $item['itemId'] : '';
            $qty    = (float) ($item['qty'] ?? 0);
            if ($itemId === '' || $qty <= 0) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \InvalidArgumentException('Cada ítem requiere itemId y qty > 0');
            }

            // Precio vigente del ítem — NUNCA lo que mande el caller: el
            // congelado tiene que reflejar lo que el negocio cobró, no lo
            // que el cliente HTTP diga que cobró (decisión 6 del plan).
            $itemRow = ncmExecute(
                'SELECT itemid, itemname, itemprice FROM item WHERE itemid = ? AND companyid = ? LIMIT 1',
                [$itemId, $companyId]
            );
            if (!$itemRow) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \InvalidArgumentException("itemId inválido para este tenant: {$itemId}");
            }
            $unitPrice = (float) ($itemRow['itemprice'] ?? 0);

            $itemOk = $db->Execute(
                'INSERT INTO voucher_item (voucheritemid, voucherid, companyid, itemid, qty, unitpriceatissue)
                 VALUES (gen_random_uuid(), ?, ?, ?, ?, ?)',
                [$voucherId, $companyId, $itemId, $qty, $unitPrice]
            );
            if ($itemOk === false) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException('No se pudo agregar un ítem al voucher');
            }

            $lines[] = [
                'itemId'           => $itemId,
                'name'             => (string) $itemRow['itemname'],
                'qty'              => $qty,
                'unitPriceAtIssue' => $unitPrice,
            ];
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al crear el voucher (transacción abortada)');
        }

        return ['voucherId' => $voucherId, 'code' => $code, 'items' => $lines];
    }

    /**
     * Valida un voucher por código: existe en el company, no está usado, no
     * está vencido, no está anulado. Devuelve la cabecera + sus ítems (el
     * POS los usa para armar las líneas del carrito).
     *
     * @return array{ok: bool, reason?: string, voucherId?: string, code?: string, expiresAt?: mixed, items?: array}
     */
    public function validate(string $companyId, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        // Match case-insensitivo: mismo criterio que giftcards.php (el
        // cajero puede tipear el código en minúscula).
        $row = ncmExecute(
            'SELECT voucherid, code, status, expiresat, usedat
               FROM voucher
              WHERE companyid = ? AND UPPER(code) = UPPER(?)
              LIMIT 1',
            [$companyId, $code]
        );
        // Guarda correcta: `!$row`. ncmExecute devuelve un CaseInsensitiveArray
        // (objeto, siempre truthy) para una fila — is_array()/(array) rompen esto.
        if (!$row) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if (!empty($row['usedat'])) {
            return ['ok' => false, 'reason' => 'used'];
        }
        if (!empty($row['expiresat']) && strtotime((string) $row['expiresat']) < time()) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        if ((int) $row['status'] !== 1) {
            return ['ok' => false, 'reason' => 'voided'];
        }

        $voucherId = (string) $row['voucherid'];
        $items = [];
        // forceObj=true: garantiza RecordsetIterator sin importar cuántas
        // filas haya (con 1 sola fila y sin forceObj, ncmExecute "aplana" a
        // array — inconsistente para una lista de tamaño variable).
        $itemsRs = ncmExecute(
            'SELECT vi.itemid, i.itemname AS name, vi.qty, vi.unitpriceatissue
               FROM voucher_item vi
               JOIN item i ON i.itemid = vi.itemid
              WHERE vi.voucherid = ? AND vi.companyid = ?
              ORDER BY vi.created_at',
            [$voucherId, $companyId],
            false,
            true
        );
        while ($itemsRs && !$itemsRs->EOF) {
            $items[] = [
                'itemId'           => (string) $itemsRs->fields['itemid'],
                'name'             => (string) $itemsRs->fields['name'],
                'qty'              => (float) $itemsRs->fields['qty'],
                'unitPriceAtIssue' => (float) $itemsRs->fields['unitpriceatissue'],
            ];
            $itemsRs->MoveNext();
        }

        return [
            'ok'        => true,
            'voucherId' => $voucherId,
            'code'      => (string) $row['code'],
            'expiresAt' => $row['expiresat'],
            'items'     => $items,
        ];
    }

    /**
     * Marca el voucher consumido con lock optimista
     * (`UPDATE ... WHERE usedat IS NULL`). Idempotente: si el mismo
     * transactionId reintenta sobre un voucher que él mismo consumió, ok.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function consume(string $companyId, string $code, string $transactionId): array
    {
        global $db;

        $code = trim($code);
        if ($code === '' || $transactionId === '') {
            throw new \InvalidArgumentException('code y transactionId son requeridos');
        }

        $row = ncmExecute(
            'SELECT voucherid, code, status, expiresat, usedat, usedbytransactionid
               FROM voucher
              WHERE companyid = ? AND UPPER(code) = UPPER(?)
              LIMIT 1',
            [$companyId, $code]
        );
        if (!$row) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if (!empty($row['usedat'])) {
            if ((string) $row['usedbytransactionid'] === $transactionId) {
                return ['ok' => true]; // re-llamada idempotente
            }
            return ['ok' => false, 'reason' => 'used_by_other'];
        }

        if (!empty($row['expiresat']) && strtotime((string) $row['expiresat']) < time()) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        if ((int) $row['status'] !== 1) {
            return ['ok' => false, 'reason' => 'voided'];
        }

        // Lock optimista: WHERE usedat IS NULL garantiza que solo un proceso
        // lo consume. Usa el code real de la fila encontrada (no el tipeado)
        // para que el UPDATE matchee — mismo patrón que giftcards.php.
        $db->Execute(
            'UPDATE voucher
                SET usedat = NOW(), usedbytransactionid = ?
              WHERE companyid = ? AND code = ? AND usedat IS NULL',
            [$transactionId, $companyId, $row['code']]
        );

        if ((int) $db->Affected_Rows() === 0) {
            return ['ok' => false, 'reason' => 'conflict'];
        }

        return ['ok' => true];
    }

    /** Código alfanumérico corto, legible al dictarlo — mismo criterio que giftcard-issue-dialog.tsx. */
    private function generateCode(): string
    {
        $bytes = random_bytes(8);
        $out   = '';
        foreach (str_split($bytes) as $b) {
            $out .= self::CODE_ALPHABET[ord($b) % strlen(self::CODE_ALPHABET)];
        }
        return self::CODE_PREFIX . $out;
    }
}
