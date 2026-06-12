<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * ItemCompoundService — gestión de recetas (ingredientes de un item de producción).
 *
 * Reemplaza el approach legacy donde los ingredientes eran items hijo con
 * data.parent_id = parentId. Acá usamos una join table dedicada (migration 19).
 *
 * El servicio devuelve los ingredientes "presentados" — con el nombre, SKU,
 * costo y UOM del item child resueltos via JOIN, listos para mostrar sin
 * lookup adicional del frontend.
 *
 * Validaciones:
 *   - parentItemId y childItemId deben existir y ser del companyId
 *   - parentItemId != childItemId (no recursión simple)
 *   - quantity > 0
 */
final class ItemCompoundService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /** Lista de ingredientes de un item, con datos del child resueltos. */
    public function listForParent(string $parentItemId, string $companyId): array
    {
        // itemUOM vive en el JSONB `data` desde la migración 07 (demoted del
        // schema físico). Hay que leerlo via `data->>'itemUOM'` — buscarlo
        // como columna física falla con "column i.itemuom does not exist".
        $sql = "SELECT ic.compoundId, ic.parentItemId, ic.childItemId, ic.quantity, ic.sort,
                       i.itemName, i.itemSKU, i.itemCost, i.itemKind,
                       i.data->>'itemUOM' AS itemUOM
                  FROM item_compound ic
             LEFT JOIN item i ON i.itemId = ic.childItemId
                 WHERE ic.parentItemId = ? AND ic.companyId = ?
                 ORDER BY ic.sort ASC, ic.created_at ASC";
        $rs = $this->db->Execute($sql, [$parentItemId, $companyId]);
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $r) {
            $qty  = (float) ($r['quantity'] ?? 0);
            $cost = (float) ($r['itemcost'] ?? $r['itemCost'] ?? 0);
            $out[] = [
                'compoundId'   => $r['compoundid']   ?? $r['compoundId']   ?? null,
                'parentItemId' => $r['parentitemid'] ?? $r['parentItemId'] ?? null,
                'childItemId'  => $r['childitemid']  ?? $r['childItemId']  ?? null,
                'quantity'     => $qty,
                'sort'         => (int) ($r['sort'] ?? 0),
                'childName'    => $r['itemname']     ?? $r['itemName']     ?? null,
                'childSKU'     => $r['itemsku']      ?? $r['itemSKU']      ?? null,
                'childUOM'     => $r['itemuom']      ?? $r['itemUOM']      ?? null,
                'childCost'    => $cost,
                'childKind'    => $r['itemkind']     ?? $r['itemKind']     ?? null,
                'lineCost'     => round($qty * $cost, 4),
            ];
        }
        return $out;
    }

    /** Crea un compound. Si ya existe (UNIQUE parent+child), suma la cantidad. */
    public function add(string $parentItemId, string $companyId, string $childItemId, float $quantity): string
    {
        if ($parentItemId === $childItemId) {
            throw new \RuntimeException('Un item no puede ser ingrediente de sí mismo');
        }
        if ($quantity <= 0) {
            throw new \RuntimeException('Cantidad debe ser mayor a 0');
        }
        $this->assertItemOwnedByCompany($childItemId, $companyId, 'child');
        $this->assertItemOwnedByCompany($parentItemId, $companyId, 'parent');

        // Si ya existe, sumamos cantidad en vez de fallar.
        $existing = $this->db->Execute(
            'SELECT compoundId, quantity FROM item_compound
              WHERE parentItemId = ? AND childItemId = ? AND companyId = ? LIMIT 1',
            [$parentItemId, $childItemId, $companyId]
        );
        if ($existing !== false && !$existing->EOF) {
            $compoundId = (string) ($existing->fields['compoundid'] ?? $existing->fields['compoundId']);
            $newQty     = (float) ($existing->fields['quantity']) + $quantity;
            $this->db->Execute(
                'UPDATE item_compound SET quantity = ? WHERE compoundId = ?',
                [$newQty, $compoundId]
            );
            return $compoundId;
        }

        $compoundId = $this->generateUuid();
        $sort       = $this->nextSort($parentItemId, $companyId);
        $ok = $this->db->Execute(
            'INSERT INTO item_compound (compoundId, parentItemId, childItemId, quantity, sort, companyId)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$compoundId, $parentItemId, $childItemId, $quantity, $sort, $companyId]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo guardar el ingrediente');
        return $compoundId;
    }

    public function updateQuantity(string $parentItemId, string $companyId, string $compoundId, float $quantity): void
    {
        if ($quantity <= 0) throw new \RuntimeException('Cantidad debe ser mayor a 0');
        $ok = $this->db->Execute(
            'UPDATE item_compound SET quantity = ?
              WHERE compoundId = ? AND parentItemId = ? AND companyId = ?',
            [$quantity, $compoundId, $parentItemId, $companyId]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo actualizar el ingrediente');
    }

    public function delete(string $parentItemId, string $companyId, string $compoundId): void
    {
        $ok = $this->db->Execute(
            'DELETE FROM item_compound
              WHERE compoundId = ? AND parentItemId = ? AND companyId = ?',
            [$compoundId, $parentItemId, $companyId]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo eliminar el ingrediente');
    }

    /** Reordena. $compoundIds[] define el orden. Filas no listadas mantienen su sort. */
    public function reorder(string $parentItemId, string $companyId, array $compoundIds): void
    {
        foreach ($compoundIds as $i => $cid) {
            $this->db->Execute(
                'UPDATE item_compound SET sort = ?
                  WHERE compoundId = ? AND parentItemId = ? AND companyId = ?',
                [$i, $cid, $parentItemId, $companyId]
            );
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function assertItemOwnedByCompany(string $itemId, string $companyId, string $role): void
    {
        $rs = $this->db->Execute(
            'SELECT 1 FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException("Item $role no existe o no pertenece a tu empresa");
        }
    }

    private function nextSort(string $parentItemId, string $companyId): int
    {
        $rs = $this->db->Execute(
            'SELECT COALESCE(MAX(sort), -1) + 1 AS next_sort FROM item_compound
              WHERE parentItemId = ? AND companyId = ?',
            [$parentItemId, $companyId]
        );
        return ($rs !== false && !$rs->EOF) ? (int) ($rs->fields['next_sort'] ?? 0) : 0;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
