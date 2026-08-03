<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

/**
 * @deprecated Reemplazado por el módulo de Espacios F0+F1
 * (`Punto\Api\Spaces\{SpaceSectorService,SpaceService,SpaceSessionService}`,
 * mig 80, context/15-espacios-module-plan.md — rename mesas→espacios en mig 81).
 * El modelo `transaction type=11/12` (mesa/orden efímera, sin historial, sin
 * sectores) es el corte limpio D3 del plan — NO se extiende. Dejar intacto
 * por compat mientras el front legacy (`app.js` `ncmSpaces`) siga vivo; no
 * consumir desde código nuevo.
 *
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

final class TableService
{
    private TransactionLinkService $links;

    public function __construct(
        public readonly TenantContext $ctx,
    ) {
        $this->links = new TransactionLinkService();
    }

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
     * Lista las mesas abiertas del outlet (tablesJson, load.php L1142).
     *
     * Devuelve un array indexado por transactionName (nro de mesa). Corrige bugs PG del legacy:
     *   - transactionName > 0 (VARCHAR vs int) → IS NOT NULL / != '' / != '0'
     *   - intval(transactionParentId) (UUID) → bool (joinSpaces roto en PG; 0|1 es suficiente)
     *   - getContactData (god-function) → simplificado a editable=true; el gate real está en la API
     *   - agrega scope por companyId (el legacy sólo filtraba outletId)
     *
     * El campo 'orders' queda en 6 (legacy hardcoded; el conteo real era demasiado lento).
     */
    public function listTables(string $companyId, string $outletId): array
    {
        global $db;
        $result = $db->Execute(
            'SELECT transactionName, transactionId, transactionDate, transactionStatus,
                    transactionNote, userId
             FROM transaction
             WHERE transactionType = ?
               AND transactionName IS NOT NULL
               AND transactionName != \'\'
               AND transactionName != \'0\'
               AND outletId = ?
               AND companyId = ?
             LIMIT 150',
            [self::TYPE_TABLE, $outletId, $companyId]
        );
        $tables = [];
        if ($result) {
            while (!$result->EOF) {
                $f = $result->fields;
                $txId = (string) ($f['transactionId'] ?? '');
                // 'joined' = esta mesa tiene un origen (fue fusionada dentro de
                // otra) — antes intval(transactionParentId), ahora vía
                // transaction_link kind='table_merge' (mig 115).
                $joined = $txId !== '' && $this->links->listOriginIds($companyId, $txId, 'table_merge') !== [];
                $tables[(string) $f['transactionName']] = [
                    'id'       => $txId,
                    'no'       => $f['transactionName'],
                    'since'    => $f['transactionDate'],
                    'status'   => (int) $f['transactionStatus'],
                    'note'     => (string) ($f['transactionNote'] ?? ''),
                    'userId'   => (string) ($f['userId'] ?? ''),
                    'orders'   => 6,
                    'editable' => true,
                    'joined'   => $joined ? 1 : 0,
                ];
                $result->MoveNext();
            }
            $result->Close();
        }
        return $tables;
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
        //    Antes: DELETE ... WHERE transactionParentId = $del. Ahora: los
        //    derivados de $del (kind='table_merge') vía transaction_link (mig 115).
        if ($kind === 'any') {
            $joinedIds = $this->links->listDerivedIds($companyId, $del, 'table_merge');
            if ($joinedIds !== []) {
                $ph = implode(',', array_fill(0, count($joinedIds), '?'));
                $db->Execute(
                    "DELETE FROM transaction
                      WHERE transactionType = ? AND transactionId IN ($ph) AND outletId = ? AND companyId = ?",
                    [self::TYPE_TABLE, ...$joinedIds, $outletId, $companyId]
                );
            }
        }

        // 3. Finalizar las órdenes asociadas (type 12) → status 4.
        $db->Execute(
            "UPDATE transaction SET transactionStatus = 4, updated_at = ?
              WHERE $matchCol = ? AND transactionType = ? AND outletId = ? AND companyId = ?",
            [TODAY, $del, self::TYPE_ORDER, $outletId, $companyId]
        );

        return ['ok' => true];
    }

    /**
     * joinSpaces (action.php L243): une la mesa $tFrom dentro de $tTo.
     *   1. Marca la mesa origen (type 11, name=$tFrom) como hija: transactionParentId =
     *      el transactionId (UUID) de la mesa destino. closeTable(kind='any') usa esa FK
     *      para cascadear el borrado de mesas unidas; listTables la lee como 'joined'.
     *   2. Reasigna las órdenes (type 12) de $tFrom a $tTo (por transactionName).
     *
     * Fija el legacy roto en PG: guardaba el NÚMERO de mesa en transactionParentId (UUID),
     * comparaba transactionName (varchar) contra int sin comillas, e interpolaba UUIDs sin
     * comillas. Acá resuelve el UUID destino y bindea todo. tFrom/tTo son nombres de mesa
     * (varchar), no ints.
     *
     * @return array{ok:bool,reason?:string,code?:int}
     */
    public function joinSpaces(string $companyId, string $outletId, string $tFrom, string $tTo): array
    {
        global $db;

        // Resolver el transactionId (UUID) de la mesa destino.
        $toRow = $db->Execute(
            'SELECT transactionId FROM transaction
              WHERE transactionType = ? AND transactionName = ? AND outletId = ? AND companyId = ?
              LIMIT 1',
            [self::TYPE_TABLE, $tTo, $outletId, $companyId]
        );
        if ($toRow === false || $toRow->EOF) {
            return ['ok' => false, 'reason' => 'Mesa destino no encontrada', 'code' => 404];
        }
        $toId = (string) $toRow->fields['transactionId'];

        // Resolver el transactionId (UUID) de la mesa origen — hace falta
        // para crear el vínculo (antes iba directo en el UPDATE).
        $fromRow = $db->Execute(
            'SELECT transactionId FROM transaction
              WHERE transactionType = ? AND transactionName = ? AND outletId = ? AND companyId = ?
              LIMIT 1',
            [self::TYPE_TABLE, $tFrom, $outletId, $companyId]
        );
        if ($fromRow === false || $fromRow->EOF) {
            return ['ok' => false, 'reason' => 'Mesa origen no encontrada', 'code' => 404];
        }
        $fromId = (string) $fromRow->fields['transactionId'];

        // 1. Marcar la mesa origen como hija (joined) de la destino — antes
        //    UPDATE transactionParentId, ahora transaction_link kind='table_merge'
        //    (mig 115): origin=destino ($toId), derived=origen ($fromId).
        $this->links->link($companyId, $toId, $fromId, 'table_merge');
        $db->Execute(
            'UPDATE transaction SET updated_at = ?
              WHERE transactionType = ? AND transactionName = ? AND outletId = ? AND companyId = ?',
            [TODAY, self::TYPE_TABLE, $tFrom, $outletId, $companyId]
        );

        // 2. Mover las órdenes (type 12) de la mesa origen a la destino.
        $db->Execute(
            'UPDATE transaction SET transactionName = ?, updated_at = ?
              WHERE transactionType = ? AND transactionName = ? AND outletId = ? AND companyId = ?',
            [$tTo, TODAY, self::TYPE_ORDER, $tFrom, $outletId, $companyId]
        );

        return ['ok' => true];
    }

    /**
     * moveOrders (action.php L263): mueve las órdenes (ítems) de la mesa $tFrom a $tTo,
     * abriendo la mesa destino si estaba cerrada (no existe como type 11 en el outlet).
     *
     * A diferencia de joinSpaces NO es una fusión: no marca la mesa origen como hija,
     * sólo reasigna las órdenes. La mesa origen queda abierta y vacía.
     *
     * Fija el legacy roto en PG: `USE INDEX` (sintaxis MySQL), params desalineados
     * (2 placeholders / 3 args), varchar vs int, UUIDs sin comillas. El INSERT usa
     * $db->Insert (el PK transactionId lo genera el DEFAULT gen_random_uuid() del schema).
     *
     * @return array{ok:bool}
     */
    public function moveOrders(
        string $companyId,
        string $outletId,
        string $registerId,
        string $userId,
        string $tFrom,
        string $tTo
    ): array {
        global $db;

        // Abrir la mesa destino si no existe (estaba cerrada).
        $toRow = $db->Execute(
            'SELECT transactionId FROM transaction
              WHERE transactionType = ? AND transactionName = ? AND outletId = ? AND companyId = ?
              LIMIT 1',
            [self::TYPE_TABLE, $tTo, $outletId, $companyId]
        );
        if ($toRow === false || $toRow->EOF) {
            // transactionId lo completa el DEFAULT gen_random_uuid() del schema.
            $db->Insert('transaction', [
                'transactionDate' => TODAY,
                'transactionName' => $tTo,
                'transactionType' => self::TYPE_TABLE,
                'responsibleId'   => $userId,
                'userId'          => $userId,
                'outletId'        => $outletId,
                'registerId'      => $registerId,
                'companyId'       => $companyId,
            ]);
        }

        // Mover las órdenes (type 12) de la mesa origen a la destino.
        $db->Execute(
            'UPDATE transaction SET transactionName = ?, updated_at = ?
              WHERE transactionType = ? AND transactionName = ? AND outletId = ? AND companyId = ?',
            [$tTo, TODAY, self::TYPE_ORDER, $tFrom, $outletId, $companyId]
        );

        return ['ok' => true];
    }
}
