<?php
declare(strict_types=1);

namespace Punto\Api\Categories;

/**
 * CategoryService — CRUD de categorías de producto (Slice 1 del refactor taxonomy).
 *
 * Lee/escribe en la tabla dedicada `category` (migration 21). El sync con
 * `taxonomy WHERE taxonomyType='category'` es automático vía triggers PG
 * bidireccionales — el POS (`/app`) puede seguir leyendo de taxonomy sin
 * cambios mientras la migración termina.
 *
 * Reglas:
 *   - Multi-tenant: TODO query filtra por companyId.
 *   - El UUID `categoryId` es el mismo que `taxonomyId` (mismo PK) — las
 *     FKs existentes (item.categoryId, item_category.categoryId,
 *     combo_group.sourceCategoryId) siguen siendo válidas.
 *   - Soft-delete NO existe en este modelo — DELETE remueve el row.
 *     El trigger propaga el DELETE a taxonomy.
 */
final class CategoryService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Lista categorías del tenant. Retorna formato `[id => { name, parentId, sort }]`
     * para compat con consumidores legacy (Reports::categories) que usan ese shape.
     *
     * @return array<string, array{name: string, parentId: ?string, extra: ?string}>
     */
    public function listIndexed(string $companyId, int $limit = 2000): array
    {
        $rs = $this->db->Execute(
            'SELECT categoryId, name, parentId, extra
               FROM category
              WHERE companyId = ?
              ORDER BY name
              LIMIT ?',
            [$companyId, $limit]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $id = (string) ($row['categoryid'] ?? $row['categoryId']);
            $out[$id] = [
                'name'     => (string) ($row['name'] ?? ''),
                'parentId' => $row['parentid'] ?? $row['parentId'] ?? null,
                'extra'    => $row['extra'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Lista formato array de objects para la API.
     *
     * @return array<int, array{id: string, name: string, parentId: ?string, extra: ?string}>
     */
    public function list(string $companyId, int $limit = 2000): array
    {
        $rs = $this->db->Execute(
            'SELECT categoryId, name, parentId, extra, created_at, updated_at
               FROM category
              WHERE companyId = ?
              ORDER BY name
              LIMIT ?',
            [$companyId, $limit]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function find(string $companyId, string $categoryId): ?array
    {
        $rs = $this->db->Execute(
            'SELECT categoryId, name, parentId, extra, created_at, updated_at
               FROM category
              WHERE categoryId = ? AND companyId = ?
              LIMIT 1',
            [$categoryId, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        // CaseInsensitiveArray: el cast (array) devuelve propiedades privadas, no
        // las keys reales. foreach + ArrayAccess copia bien.
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    /**
     * @return string el categoryId nuevo
     * @throws \RuntimeException si el INSERT falla
     */
    public function create(string $companyId, array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('name requerido');
        }
        $parentId = !empty($input['parentId']) ? (string) $input['parentId'] : null;
        $extra    = isset($input['extra']) ? (string) $input['extra'] : null;
        $outletId = !empty($input['outletId']) ? (string) $input['outletId'] : null;

        $categoryId = $this->generateUuid();
        $ok = $this->db->Execute(
            'INSERT INTO category (categoryId, companyId, outletId, name, parentId, extra)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$categoryId, $companyId, $outletId, $name, $parentId, $extra]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo crear la categoría');
        }
        return $categoryId;
    }

    public function update(string $companyId, string $categoryId, array $input): void
    {
        $sets   = [];
        $params = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') throw new \RuntimeException('name no puede estar vacío');
            $sets[]   = 'name = ?';
            $params[] = $name;
        }
        if (array_key_exists('parentId', $input)) {
            $sets[]   = 'parentId = ?';
            $params[] = !empty($input['parentId']) ? (string) $input['parentId'] : null;
        }
        if (array_key_exists('extra', $input)) {
            $sets[]   = 'extra = ?';
            $params[] = $input['extra'] !== null ? (string) $input['extra'] : null;
        }
        if (array_key_exists('outletId', $input)) {
            $sets[]   = 'outletId = ?';
            $params[] = !empty($input['outletId']) ? (string) $input['outletId'] : null;
        }
        if (empty($sets)) return;

        $sets[]    = 'updated_at = NOW()';
        $params[]  = $categoryId;
        $params[]  = $companyId;
        $sql = 'UPDATE category SET ' . implode(', ', $sets)
             . ' WHERE categoryId = ? AND companyId = ?';
        $ok = $this->db->Execute($sql, $params);
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar la categoría');
        }
    }

    public function delete(string $companyId, string $categoryId): void
    {
        $ok = $this->db->Execute(
            'DELETE FROM category WHERE categoryId = ? AND companyId = ?',
            [$categoryId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo eliminar la categoría');
        }
    }

    private function present(array|\CaseInsensitiveArray $row): array
    {
        return [
            'id'         => (string) ($row['categoryid'] ?? $row['categoryId'] ?? ''),
            'name'       => (string) ($row['name'] ?? ''),
            'parentId'   => $row['parentid'] ?? $row['parentId'] ?? null,
            'extra'      => $row['extra'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
