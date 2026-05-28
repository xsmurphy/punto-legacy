<?php
/**
 * TableService — operaciones sobre las mesas/espacios del POS (slice 2 del desacople de /app).
 *
 * Una "mesa" es una fila de `transaction` con transactionType = 11, identificada por
 * `transactionName` (el número/nombre de mesa, VARCHAR) dentro de un outlet.
 *
 * Lógica portada de app/action.php (renameTable 255, unReserveTable 319). Como en el
 * resto del desacople: escrituras con $db->Execute PARAMETRIZADO (no ncm*), corrigiendo
 * bugs PG latentes del legacy:
 *   - OUTLET_ID se bindea (el legacy lo interpolaba sin comillas → roto en PG).
 *   - transactionName es VARCHAR → se compara como string bindeado (el legacy interpolaba
 *     un int sin comillas contra varchar → error de tipos en PG).
 *   - se agrega scope por companyId (el legacy sólo filtraba por outletId).
 */

class TableService
{
    const TYPE_TABLE = 11;
    const TYPE_ORDER = 12;

    /** Renombra (setea la nota visible) de una mesa. */
    public function rename(string $companyId, string $outletId, string $tableName, string $note): array
    {
        global $db;
        $res = $db->Execute(
            'UPDATE transaction SET transactionNote = ?
              WHERE companyId = ? AND outletId = ? AND transactionType = ? AND transactionName = ?',
            [strip_tags($note), $companyId, $outletId, self::TYPE_TABLE, $tableName]
        );
        return ['ok' => $res !== false];
    }

    /** Libera la reserva de una mesa (transactionStatus = 1). */
    public function unreserve(string $companyId, string $outletId, string $tableName): array
    {
        global $db;
        $res = $db->Execute(
            'UPDATE transaction SET transactionStatus = 1, updated_at = ?
              WHERE companyId = ? AND outletId = ? AND transactionType = ? AND transactionName = ?',
            [TODAY, $companyId, $outletId, self::TYPE_TABLE, $tableName]
        );
        return ['ok' => $res !== false];
    }

    /** Asigna un usuario (mozo/responsable) a una mesa/espacio. */
    public function assignUser(string $companyId, string $outletId, string $tableName, string $userId): array
    {
        global $db;
        $res = $db->Execute(
            'UPDATE transaction SET userId = ?
              WHERE companyId = ? AND outletId = ? AND transactionType = ? AND transactionName = ?',
            [$userId, $companyId, $outletId, self::TYPE_TABLE, $tableName]
        );
        return ['ok' => $res !== false];
    }

    /**
     * Cierra una mesa/espacio (closeTable, action.php L225). Tres operaciones:
     *   1. Borra la mesa abierta (type 11) que matchea $del según $kind.
     *   2. Borra mesas unidas (type 11 con transactionParentId = $del) — SÓLO kind='any'
     *      (donde $del es un transactionId/uuid). El legacy lo corría para todos los kinds,
     *      pero el efecto neto es idéntico restringiéndolo a 'any':
     *        - kind='customer': $del (uuid) vs columna uuid → type-válido pero matchea 0 filas
     *          (un customerId nunca es FK de transactionParentId).
     *        - kind='table': $del (nombre varchar) vs columna uuid → type-error PG → no-op.
     *      Restringir a 'any' borra exactamente las mismas filas, sin el error PG.
     *   3. Finaliza las órdenes asociadas (type 12) → transactionStatus = 4.
     *
     * El handler legacy devuelve success incondicionalmente (ignora los resultados de los
     * DELETE), así que esta función también retorna ok=true salvo error duro de conexión.
     *
     * @param string $kind 'any' (transactionId) | 'customer' (customerId) | 'table' (transactionName)
     * @param string $del  el identificador a matchear, según $kind.
     */
    public function closeTable(string $companyId, string $outletId, string $kind, string $del): array
    {
        global $db;

        // Columna de match según kind (literal interno, no input → seguro interpolar).
        $matchCol = match ($kind) {
            'any'      => 'transactionId',
            'customer' => 'customerId',
            default    => 'transactionName', // 'table'
        };

        // 1. Borrar la mesa abierta (type 11).
        $db->Execute(
            "DELETE FROM transaction
              WHERE transactionType = ? AND $matchCol = ? AND outletId = ? AND companyId = ?",
            [self::TYPE_TABLE, $del, $outletId, $companyId]
        );

        // 2. Borrar mesas unidas — sólo cuando $del es un transactionId (kind='any').
        if ($kind === 'any') {
            $db->Execute(
                'DELETE FROM transaction
                  WHERE transactionType = ? AND transactionParentId = ? AND outletId = ? AND companyId = ?',
                [self::TYPE_TABLE, $del, $outletId, $companyId]
            );
        }

        // 3. Finalizar las órdenes asociadas (type 12) → status 4.
        $db->Execute(
            "UPDATE transaction SET transactionStatus = 4, updated_at = ?
              WHERE $matchCol = ? AND transactionType = ? AND outletId = ? AND companyId = ?",
            [TODAY, $del, self::TYPE_ORDER, $outletId, $companyId]
        );

        return ['ok' => true];
    }
}
