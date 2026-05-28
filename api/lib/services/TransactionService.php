<?php
/**
 * TransactionService — operaciones sobre transacciones/órdenes del POS (Slice 6).
 *
 * Lógica portada de app/action.php:
 *   deleteTransaction  (L1188) — elimina una transacción
 *   deleteInPrintServer (L155) — elimina un job de la cola de impresión
 *   rejectOrder        (L1260) — rechaza una orden (status 6); la notif WS va al caller
 *   recordItemDeletion  (L605) — inserta en itemDeleted (historial de borrado de ítems)
 *
 * Bugs PG corregidos respecto al legacy:
 *   - LIMIT 1 en DELETE → eliminado (transactionId es PK, limita solo).
 *   - db_prepare() interpolado en WHERE → bindeo parametrizado.
 *   - UUIDs enviados sin comillas en SQL → bindeados.
 *
 * El caller (api/v1/transactions.php) es responsable de los efectos secundarios
 * (sendWS, updateLastTimeEdit) para que el servicio sea puro de BD.
 */

class TransactionService
{
    /**
     * Elimina una transacción. Requiere scope companyId (tenant).
     * LIMIT 1 omitido: transactionId es PRIMARY KEY → único por definición.
     */
    public function delete(string $transactionId, string $companyId): bool
    {
        global $db;
        $res = $db->Execute(
            'DELETE FROM transaction WHERE "transactionId" = ? AND "companyId" = ?',
            [$transactionId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Elimina un job de la cola printServer por transactionId + companyId.
     */
    public function deletePrintJob(string $transactionId, string $companyId): bool
    {
        global $db;
        $res = $db->Execute(
            'DELETE FROM "printServer" WHERE "transactionId" = ? AND "companyId" = ?',
            [$transactionId, $companyId]
        );
        return $res !== false;
    }

    /**
     * Rechaza una orden: transactionStatus = 6 + nota opcional.
     * La notificación WS (sendWS) queda en el caller (api/v1/transactions.php).
     *
     * @param string      $transactionId  UUID de la transacción.
     * @param string      $companyId      UUID del tenant.
     * @param string|null $motive         Motivo del rechazo (opcional). Se strip_tags.
     * @return bool       true si no hubo error de BD (igual que el legacy !==false; no implica que existía).
     */
    public function reject(string $transactionId, string $companyId, ?string $motive): bool
    {
        global $db;

        $setCols = '"transactionStatus" = ?, "updated_at" = NOW()';
        $params  = [6];

        if ($motive !== null && $motive !== '') {
            $setCols  .= ', "transactionNote" = ?';
            $params[]  = strip_tags($motive);
        }
        $params[] = $transactionId;
        $params[] = $companyId;

        $res = $db->Execute(
            "UPDATE transaction SET $setCols WHERE \"transactionId\" = ? AND \"companyId\" = ?",
            $params
        );
        return $res !== false;
    }

    /**
     * Registra la eliminación de un ítem en el historial (itemDeleted).
     * El motive se sanitiza con markupt2HTML (igual que el legacy; preserva formato markup).
     *
     * @param string $itemId    UUID del ítem eliminado.
     * @param string $motive    Motivo del borrado.
     * @param string $userId    UUID del usuario que lo borró (se guarda en el JSON data).
     * @param string $companyId UUID del tenant.
     * @param string $outletId  UUID del outlet.
     */
    public function recordItemDeletion(
        string $itemId,
        string $motive,
        string $userId,
        string $companyId,
        string $outletId
    ): bool {
        global $db;
        $data = json_encode(['motive' => markupt2HTML(['text' => $motive, 'type' => 'HtM']), 'user' => $userId]);
        $res  = $db->Execute(
            'INSERT INTO "itemDeleted" ("itemId", "date", "data", "companyId", "outletId")
             VALUES (?, NOW(), ?, ?, ?)',
            [$itemId, $data, $companyId, $outletId]
        );
        return $res !== false;
    }
}
