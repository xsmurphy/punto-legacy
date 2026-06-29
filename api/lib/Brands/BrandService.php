<?php
declare(strict_types=1);

namespace Punto\Api\Brands;

/**
 * BrandService — CRUD de marcas de producto (Slice 2 del refactor taxonomy).
 *
 * Tabla dedicada `brand` (migration 22). Sync con `taxonomy WHERE
 * taxonomyType='brand'` vía triggers PG bidireccionales.
 *
 * Mismo patrón que CategoryService — copiar/modificar para nuevos tipos.
 */
final class BrandService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, array{name: string, extra: ?string}>
     */
    public function listIndexed(string $companyId, int $limit = 500): array
    {
        $rs = $this->db->Execute(
            'SELECT brandId, name, extra
               FROM brand
              WHERE companyId = ?
              ORDER BY name
              LIMIT ?',
            [$companyId, $limit]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $id = (string) ($row['brandid'] ?? $row['brandId']);
            $out[$id] = [
                'name'  => (string) ($row['name'] ?? ''),
                'extra' => $row['extra'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @return array<int, array{id: string, name: string, extra: ?string}>
     */
    public function list(string $companyId, int $limit = 500): array
    {
        $rs = $this->db->Execute(
            'SELECT brandId, name, extra, created_at, updated_at
               FROM brand
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

    public function find(string $companyId, string $brandId): ?array
    {
        $rs = $this->db->Execute(
            'SELECT brandId, name, extra, created_at, updated_at
               FROM brand
              WHERE brandId = ? AND companyId = ?
              LIMIT 1',
            [$brandId, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        // CaseInsensitiveArray: el cast (array) no expone las keys/values reales
        // — devuelve las propiedades privadas. foreach + ArrayAccess sí copia.
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    public function create(string $companyId, array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new \RuntimeException('name requerido');
        }
        $extra    = isset($input['extra']) ? (string) $input['extra'] : null;
        $outletId = !empty($input['outletId']) ? (string) $input['outletId'] : null;

        $brandId = $this->generateUuid();
        $ok = $this->db->Execute(
            'INSERT INTO brand (brandId, companyId, outletId, name, extra)
             VALUES (?, ?, ?, ?, ?)',
            [$brandId, $companyId, $outletId, $name, $extra]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo crear la marca');
        }
        return $brandId;
    }

    public function update(string $companyId, string $brandId, array $input): void
    {
        $sets   = [];
        $params = [];
        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') throw new \RuntimeException('name no puede estar vacío');
            $sets[]   = 'name = ?';
            $params[] = $name;
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
        $params[]  = $brandId;
        $params[]  = $companyId;
        $sql = 'UPDATE brand SET ' . implode(', ', $sets)
             . ' WHERE brandId = ? AND companyId = ?';
        $ok = $this->db->Execute($sql, $params);
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar la marca');
        }
    }

    public function delete(string $companyId, string $brandId): void
    {
        $ok = $this->db->Execute(
            'DELETE FROM brand WHERE brandId = ? AND companyId = ?',
            [$brandId, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo eliminar la marca');
        }
    }

    // ── item_brand m2m ────────────────────────────────────────────────────

    /**
     * Marcas asignadas a un item con su flag isPrimary.
     *
     * @return array<int, array{id: string, name: string, isPrimary: bool}>
     */
    public function listForItem(string $itemId): array
    {
        $rs = $this->db->Execute(
            'SELECT b.brandId, b.name, ib.isPrimary
               FROM item_brand ib
               JOIN brand b ON b.brandId = ib.brandId
              WHERE ib.itemId = ?
              ORDER BY ib.isPrimary DESC, b.name',
            [$itemId]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = [
                'id'        => (string) ($row['brandid'] ?? $row['brandId']),
                'name'      => (string) ($row['name'] ?? ''),
                'isPrimary' => (bool) ($row['isprimary'] ?? $row['isPrimary'] ?? false),
            ];
        }
        return $out;
    }

    /**
     * Reemplaza todas las marcas asignadas a un item. `$brands` es array de
     * `['id' => uuid, 'isPrimary' => bool]`. Multi-tenant: el caller pasa
     * companyId verificado.
     */
    public function syncForItem(string $itemId, string $companyId, array $brands): void
    {
        $this->db->Execute('DELETE FROM item_brand WHERE itemId = ?', [$itemId]);
        foreach ($brands as $b) {
            $id        = (string) ($b['id'] ?? '');
            $isPrimary = !empty($b['isPrimary']);
            if ($id === '') continue;
            $this->db->Execute(
                'INSERT INTO item_brand (itemId, brandId, isPrimary)
                 VALUES (?, ?, ?)
                 ON CONFLICT DO NOTHING',
                [$itemId, $id, $isPrimary ? 'true' : 'false']
            );
        }
    }

    private function present(array|\CaseInsensitiveArray $row): array
    {
        return [
            'id'         => (string) ($row['brandid'] ?? $row['brandId'] ?? ''),
            'name'       => (string) ($row['name'] ?? ''),
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
