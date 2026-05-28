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
}
