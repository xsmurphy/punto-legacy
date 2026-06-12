<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * ComboGroupService — grupos de componentes para combos dinámicos.
 *
 * Modelo híbrido: cada grupo es de tipo 'items' (lista explícita) o 'category'
 * (cualquier item de una categoría). PUT replaza el grupo completo, incluyendo
 * sus items — más fácil de razonar para el frontend que partial updates row-by-row.
 *
 * Combo fijo usa item_compound (la otra tabla). Combo dinámico usa estas dos.
 */
final class ComboGroupService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function listForParent(string $parentItemId, string $companyId): array
    {
        $rs = $this->db->Execute(
            'SELECT g.groupId, g.name, g.sourceType, g.sourceCategoryId,
                    g.minSelection, g.maxSelection, g.sort,
                    cat.taxonomyName AS categoryName
               FROM combo_group g
          LEFT JOIN taxonomy cat ON cat.taxonomyId = g.sourceCategoryId
              WHERE g.parentItemId = ? AND g.companyId = ?
              ORDER BY g.sort ASC, g.created_at ASC',
            [$parentItemId, $companyId]
        );
        if ($rs === false) return [];
        $groups = [];
        foreach ($rs->GetRows() as $row) {
            $groupId = (string) ($row['groupid'] ?? $row['groupId']);
            $groups[] = [
                'groupId'          => $groupId,
                'name'             => $row['name'] ?? '',
                'sourceType'       => $row['sourcetype']       ?? $row['sourceType']       ?? 'items',
                'sourceCategoryId' => $row['sourcecategoryid'] ?? $row['sourceCategoryId'] ?? null,
                'sourceCategoryName' => $row['categoryname']   ?? $row['categoryName']     ?? null,
                'minSelection'     => (int) ($row['minselection'] ?? $row['minSelection'] ?? 1),
                'maxSelection'     => (int) ($row['maxselection'] ?? $row['maxSelection'] ?? 1),
                'sort'             => (int) ($row['sort'] ?? 0),
                'items'            => $this->listItemsForGroup($groupId),
            ];
        }
        return $groups;
    }

    public function create(string $parentItemId, string $companyId, array $input): string
    {
        $this->assertItemOwnedByCompany($parentItemId, $companyId);
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') throw new \RuntimeException('Falta el nombre del grupo');

        $sourceType = (string) ($input['sourceType'] ?? 'items');
        if (!in_array($sourceType, ['items', 'category'], true)) {
            throw new \RuntimeException('sourceType inválido');
        }
        $sourceCategoryId = $sourceType === 'category'
            ? (string) ($input['sourceCategoryId'] ?? '')
            : null;
        if ($sourceType === 'category' && ($sourceCategoryId === null || $sourceCategoryId === '')) {
            throw new \RuntimeException('sourceCategoryId requerido para sourceType=category');
        }

        $min = (int) ($input['minSelection'] ?? 1);
        $max = (int) ($input['maxSelection'] ?? max(1, $min));
        if ($min < 0 || $max < $min) throw new \RuntimeException('Min/Max selection inválidos');

        $groupId = $this->generateUuid();
        $sort    = $this->nextSort($parentItemId, $companyId);

        $ok = $this->db->Execute(
            'INSERT INTO combo_group (groupId, parentItemId, companyId, name, sourceType, sourceCategoryId, minSelection, maxSelection, sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$groupId, $parentItemId, $companyId, $name, $sourceType, $sourceCategoryId, $min, $max, $sort]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo crear el grupo');

        // Si vienen items, los agregamos (solo aplica si sourceType='items').
        if ($sourceType === 'items' && !empty($input['items']) && is_array($input['items'])) {
            $this->replaceItems($groupId, $input['items']);
        }

        return $groupId;
    }

    public function update(string $parentItemId, string $companyId, string $groupId, array $input): void
    {
        $current = $this->findGroup($groupId, $parentItemId, $companyId);
        if ($current === null) throw new \RuntimeException('Grupo no encontrado');

        $sets   = [];
        $params = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') throw new \RuntimeException('El nombre no puede estar vacío');
            $sets[] = 'name = ?'; $params[] = $name;
        }
        if (array_key_exists('sourceType', $input)) {
            $st = (string) $input['sourceType'];
            if (!in_array($st, ['items', 'category'], true)) {
                throw new \RuntimeException('sourceType inválido');
            }
            $sets[] = 'sourceType = ?'; $params[] = $st;
        }
        if (array_key_exists('sourceCategoryId', $input)) {
            $sets[] = 'sourceCategoryId = ?';
            $params[] = $input['sourceCategoryId'] ?: null;
        }
        if (array_key_exists('minSelection', $input)) {
            $sets[] = 'minSelection = ?'; $params[] = (int) $input['minSelection'];
        }
        if (array_key_exists('maxSelection', $input)) {
            $sets[] = 'maxSelection = ?'; $params[] = (int) $input['maxSelection'];
        }

        if (!empty($sets)) {
            $params[] = $groupId; $params[] = $parentItemId; $params[] = $companyId;
            $this->db->Execute(
                'UPDATE combo_group SET ' . implode(', ', $sets)
                . ' WHERE groupId = ? AND parentItemId = ? AND companyId = ?',
                $params
            );
        }

        // PUT reemplaza items si el caller los manda explícitamente.
        if (array_key_exists('items', $input) && is_array($input['items'])) {
            $this->replaceItems($groupId, $input['items']);
        }
    }

    public function delete(string $parentItemId, string $companyId, string $groupId): void
    {
        // CASCADE en FK borra los combo_group_item asociados.
        $ok = $this->db->Execute(
            'DELETE FROM combo_group WHERE groupId = ? AND parentItemId = ? AND companyId = ?',
            [$groupId, $parentItemId, $companyId]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo eliminar el grupo');
    }

    public function reorder(string $parentItemId, string $companyId, array $groupIds): void
    {
        foreach ($groupIds as $i => $gid) {
            $this->db->Execute(
                'UPDATE combo_group SET sort = ?
                  WHERE groupId = ? AND parentItemId = ? AND companyId = ?',
                [$i, $gid, $parentItemId, $companyId]
            );
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function listItemsForGroup(string $groupId): array
    {
        $rs = $this->db->Execute(
            'SELECT gi.groupItemId, gi.childItemId, gi.extraPrice, gi.isPreselected, gi.sort,
                    i.itemName, i.itemSKU, i.itemPrice, i.itemUOM, i.itemKind
               FROM combo_group_item gi
          LEFT JOIN item i ON i.itemId = gi.childItemId
              WHERE gi.groupId = ?
              ORDER BY gi.sort ASC, gi.created_at ASC',
            [$groupId]
        );
        if ($rs === false) return [];
        $items = [];
        foreach ($rs->GetRows() as $r) {
            $items[] = [
                'groupItemId'   => $r['groupitemid'] ?? $r['groupItemId'],
                'childItemId'   => $r['childitemid'] ?? $r['childItemId'],
                'extraPrice'    => (float) ($r['extraprice']  ?? $r['extraPrice']  ?? 0),
                'isPreselected' => (bool)  ($r['ispreselected'] ?? $r['isPreselected'] ?? false),
                'sort'          => (int)   ($r['sort'] ?? 0),
                'childName'     => $r['itemname']    ?? $r['itemName']    ?? null,
                'childSKU'      => $r['itemsku']     ?? $r['itemSKU']     ?? null,
                'childPrice'    => (float) ($r['itemprice'] ?? $r['itemPrice'] ?? 0),
                'childUOM'      => $r['itemuom']     ?? $r['itemUOM']     ?? null,
                'childKind'     => $r['itemkind']    ?? $r['itemKind']    ?? null,
            ];
        }
        return $items;
    }

    /** Reemplaza los items del grupo con la lista recibida. */
    private function replaceItems(string $groupId, array $items): void
    {
        $this->db->Execute('DELETE FROM combo_group_item WHERE groupId = ?', [$groupId]);
        foreach ($items as $i => $raw) {
            $childItemId   = (string) ($raw['childItemId'] ?? '');
            if ($childItemId === '') continue;
            $extraPrice    = (float) ($raw['extraPrice'] ?? 0);
            $isPreselected = !empty($raw['isPreselected']);
            $sort          = (int) ($raw['sort'] ?? $i);
            $groupItemId   = $this->generateUuid();
            $this->db->Execute(
                'INSERT INTO combo_group_item (groupItemId, groupId, childItemId, extraPrice, isPreselected, sort)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (groupId, childItemId) DO UPDATE
                   SET extraPrice = EXCLUDED.extraPrice,
                       isPreselected = EXCLUDED.isPreselected,
                       sort = EXCLUDED.sort',
                [$groupItemId, $groupId, $childItemId, $extraPrice, $isPreselected ? 'true' : 'false', $sort]
            );
        }
    }

    private function findGroup(string $groupId, string $parentItemId, string $companyId): ?array
    {
        $rs = $this->db->Execute(
            'SELECT groupId FROM combo_group
              WHERE groupId = ? AND parentItemId = ? AND companyId = ? LIMIT 1',
            [$groupId, $parentItemId, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        return ['groupId' => (string) ($rs->fields['groupid'] ?? $rs->fields['groupId'])];
    }

    private function assertItemOwnedByCompany(string $itemId, string $companyId): void
    {
        $rs = $this->db->Execute(
            'SELECT 1 FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('Item no existe o no pertenece a tu empresa');
        }
    }

    private function nextSort(string $parentItemId, string $companyId): int
    {
        $rs = $this->db->Execute(
            'SELECT COALESCE(MAX(sort), -1) + 1 AS next_sort FROM combo_group
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
