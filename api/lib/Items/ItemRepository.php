<?php
declare(strict_types=1);

namespace Punto\Api\Items;

use CaseInsensitiveArray;
use Punto\Api\Support\DbQueryException;

/**
 * ItemRepository — acceso a la tabla `item` con SQL parametrizado.
 *
 * Solo SQL. Sin reglas de negocio, sin defaults, sin side-effects.
 * Las reglas viven en ItemService. El JSONB routing lo hacen ncmInsert/ncmUpdate.
 *
 * Port FIEL de panel/lib/items/ItemRepository.php (Fase 2 del desacople de /panel).
 * Cambios respecto al original: namespace, `final`, `declare(strict_types=1)`,
 * `use CaseInsensitiveArray`. SQL idéntico.
 */
final class ItemRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Buscar item por id + companyId. Devuelve CaseInsensitiveArray (accede por itemName o itemname).
     */
    public function find(string $id, string $companyId): array|CaseInsensitiveArray|null
    {
        $sql = "SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1";
        $rs  = $this->db->Execute($sql, [$id, $companyId]);
        if ($rs === false || $rs->EOF) return null;
        return _flattenJsonb($rs->fields);
    }

    /**
     * INSERT vía ncmInsert (genera UUID v7 + JSONB routing).
     * Retorna el itemId nuevo o false si falla.
     */
    public function create(array $record)
    {
        return ncmInsert(['table' => 'item', 'records' => $record]);
    }

    /**
     * UPDATE por itemId + companyId. Retorna true/false.
     */
    public function update(string $id, string $companyId, array $record): bool
    {
        $ok = ncmUpdate([
            'table'       => 'item',
            'records'     => $record,
            'where'       => 'itemId = ? AND companyId = ?',
            'whereParams' => [$id, $companyId],
        ]);
        // ncmUpdate devuelve ['error'=>false,...] en éxito o ['error'=>$msg] en fallo (nunca false).
        return is_array($ok) && empty($ok['error']);
    }

    /**
     * Soft-delete: itemStatus = 0.
     *
     * `updated_at = TODAY` (reloj del server PHP), NO `NOW()` de Postgres —
     * el sync incremental (context/43-sync-incremental.md) compara este
     * campo contra un watermark generado con `TODAY` (`SyncService::
     * watermarks()`). Si esta fila usara el reloj de la DB en vez del reloj
     * de PHP, un desfase entre ambos (aunque sea de milisegundos) podría
     * dejar un archive() justo en el borde de una ventana de delta sin
     * aparecer — el peor bug posible acá es una actualización perdida en
     * silencio. Mismo criterio ya usado por el resto de los writes de
     * `item`/`contact` (`ItemService::update()`, `ContactService::update()`).
     */
    public function archive(string $id, string $companyId): bool
    {
        $sql = "UPDATE item SET itemStatus = 0, updated_at = ? WHERE itemId = ? AND companyId = ?";
        return $this->db->Execute($sql, [TODAY, $id, $companyId]) !== false;
    }

    /**
     * Hard-delete: DELETE físico de la tabla `item`.
     *
     * Precondición: el item debe tener itemStatus = 0 (archivado).
     * Guards (en orden):
     *   1. Ventas: filas en itemSold como ítem vendido (itemId) o como parent de
     *      un compuesto vendido (itemSoldParent) → 'sold' (mensaje de negocio).
     *   2. Cualquier otra FK que referencia item (stock, inventory, stockTrigger,
     *      toCompound, production, upsell, item hijos, etc.) está declarada como
     *      RESTRICT en el schema → el DELETE falla con violación de FK. La
     *      detectamos de forma genérica (sin enumerar cada tabla) y devolvemos
     *      'referenced' en vez de un false ambiguo.
     *
     * NO LLAMAR DENTRO DE UNA TRANSACCIÓN ABIERTA — y la restricción está
     * IMPUESTA, no solo documentada (ver `$callerInTrans` abajo). El
     * `catch (DbQueryException)` que traduce la violación de FK a 'referenced'
     * solo es correcto si este DELETE es la única escritura en vuelo: cuando el
     * wrapper lanza ya hizo rollback de TODA la transacción
     * (`DB::failTransaction()`), así que un caller que envolviera esto en
     * `StartTrans()` recibiría 'referenced' —un resultado de negocio normal—
     * mientras su propio trabajo desapareció sin aviso. Hoy ningún call-site
     * abre transacción alrededor; si alguno lo necesita, el pre-check de FK
     * tiene que hacerse con un SELECT antes, no con el catch.
     *
     * @return true|'sold'|'referenced'|false
     *   - true          → eliminado OK
     *   - 'sold'        → tiene ventas asociadas, no se puede borrar
     *   - 'referenced'  → referenciado por otros registros (stock/inventario/etc.)
     *   - false         → item no encontrado / no archivado (o error de DB con el
     *                     kill-switch DB_THROW_ON_ERROR apagado)
     */
    public function hardDelete(string $id, string $companyId)
    {
        // Guard 1: ventas asociadas (scope por companyId via JOIN a transaction).
        // Cubre el ítem vendido directo (s.itemId) y el parent de un compuesto
        // vendido (s.itemSoldParent), que de otro modo se saltaría el chequeo.
        $countRow = ncmExecute(
            "SELECT COUNT(*) AS n FROM itemSold s
               JOIN transaction t ON t.transactionId = s.transactionId
              WHERE (s.itemId = ? OR s.itemSoldParent = ?) AND t.companyId = ?",
            [$id, $id, $companyId]
        );
        $soldCount = (int) ($countRow['n'] ?? 0);
        if ($soldCount > 0) {
            return 'sold';
        }

        // Guard: variantes activas impiden borrar el padre.
        $vcRow = ncmExecute(
            'SELECT COUNT(*) AS n FROM item WHERE variantParentId = ? AND companyId = ? AND itemStatus = 1',
            [$id, $companyId]
        );
        $vcCount = (int) ($vcRow['n'] ?? 0);
        if ($vcCount > 0) {
            return 'referenced';
        }

        // Se captura ANTES del DELETE, no dentro del catch: `failTransaction()`
        // cierra la transacción y pone `transDepth = 0` antes de lanzar, así
        // que preguntarlo en el catch devuelve false SIEMPRE y el guard nunca
        // dispararía.
        //
        // `HasOpenTransaction()` y no `InTrans()`: esta última solo cuenta el
        // anidamiento de `StartTrans()` y dejaría ciego al patrón
        // `BeginTrans()/CommitTrans()`, que rompe exactamente igual.
        $callerInTrans = $this->db->HasOpenTransaction();

        // Solo borrar si está archivado (itemStatus=0) y pertenece a la company.
        $sql = "DELETE FROM item WHERE itemId = ? AND companyId = ? AND itemStatus = 0";
        try {
            $this->db->Execute($sql, [$id, $companyId]);
        } catch (DbQueryException $e) {
            // Si veníamos DENTRO de una transacción del caller, el wrapper ya la
            // rollbackeó ENTERA antes de lanzar. Devolver 'referenced' acá sería
            // reportar un resultado de negocio benigno sobre un trabajo que se
            // evaporó (el caller commitearía "ok" sobre la nada). Se relanza.
            if ($callerInTrans) {
                error_log('[ItemRepository::hardDelete] llamado dentro de una transacción abierta; '
                    . 'el rollback del wrapper ya descartó el trabajo del caller. Relanzando: ' . $e->getMessage());
                throw $e;
            }
            // Guard 2: las FK a item son RESTRICT → un DELETE bloqueado lanza
            // violación de FK (PG SQLSTATE 23503). La traducimos a 'referenced'
            // para devolver un 409 claro en vez de un 500/422 confuso. Antes se
            // leía de `ErrorMsg()` tras el `false`; el wrapper ahora lanza.
            $err = $e->getMessage();
            if ($e->sqlState() === '23503'
                || stripos($err, 'foreign key') !== false
                || stripos($err, '23503') !== false) {
                return 'referenced';
            }
            // Cualquier otro error de BD se propaga: el `return false` de antes
            // se mostraba como "no se pudo borrar" sin decir por qué.
            throw $e;
        }
        // AffectedRows() = 0 significa que no encontró la fila (no archivado o no existe).
        return $this->db->Affected_Rows() > 0;
    }

    /**
     * Búsqueda por nombre/SKU (ILIKE). Retorna array de filas crudas (CaseInsensitiveArray).
     */
    public function searchByName(string $pattern, string $companyId, int $limit = 200): array
    {
        $like = '%' . strtolower($pattern) . '%';
        $sql  = "SELECT itemId, itemName, itemSKU, taxId, data
                 FROM item
                 WHERE (LOWER(itemName) ILIKE ? OR LOWER(itemSKU) ILIKE ?)
                   AND companyId = ? AND itemStatus = 1
                 LIMIT " . (int) $limit;
        $rs   = $this->db->Execute($sql, [$like, $like, $companyId]);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = _flattenJsonb($row);
        }
        return $out;
    }

    /**
     * IDs de hijos de un grupo (itemParentId apunta a $parentId).
     * Distinto a findChildren() que usa el legacy parent_id del JSONB.
     */
    public function listChildIds(string $parentId, string $companyId): array
    {
        $sql = "SELECT itemId FROM item
                 WHERE itemParentId = ? AND companyId = ? AND itemStatus = 1
                 LIMIT 500";
        $rs  = $this->db->Execute($sql, [$parentId, $companyId]);
        if ($rs === false) return [];
        $ids = [];
        foreach ($rs->GetRows() as $row) {
            $ids[] = (string) ($row['itemid'] ?? $row['itemId']);
        }
        return $ids;
    }

    /**
     * Hijos de un compound/combo (parent_id en data JSONB).
     */
    public function findChildren(string $parentId, string $companyId): array
    {
        $sql = "SELECT * FROM item
                WHERE companyId = ?
                  AND itemStatus = 1
                  AND data->>'parent_id' = ?";
        $rs  = $this->db->Execute($sql, [$companyId, $parentId]);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = _flattenJsonb($row);
        }
        return $out;
    }
}
