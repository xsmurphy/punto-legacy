<?php

/**
 * ItemRepository — acceso a la tabla `item` con SQL parametrizado.
 *
 * Solo SQL. Sin reglas de negocio, sin defaults, sin side-effects.
 * Las reglas viven en ItemService. El JSONB routing lo hacen ncmInsert/ncmUpdate.
 */
class ItemRepository
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Buscar item por id + companyId. Devuelve CaseInsensitiveArray (accede por itemName o itemname).
     */
    public function find(string $id, string $companyId): ?CaseInsensitiveArray
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
     */
    public function archive(string $id, string $companyId): bool
    {
        $sql = "UPDATE item SET itemStatus = 0, updated_at = NOW() WHERE itemId = ? AND companyId = ?";
        return $this->db->Execute($sql, [$id, $companyId]) !== false;
    }

    /**
     * Búsqueda por nombre/SKU (ILIKE). Retorna array de filas crudas (CaseInsensitiveArray).
     */
    public function searchByName(string $pattern, string $companyId, int $limit = 200): array
    {
        $like = '%' . strtolower($pattern) . '%';
        $sql  = "SELECT itemId, itemName, itemSKU, itemUOM, taxId
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
