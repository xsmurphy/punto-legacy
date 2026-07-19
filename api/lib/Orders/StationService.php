<?php
declare(strict_types=1);

namespace Punto\Api\Orders;

/**
 * StationService — CRUD de estaciones de preparación (order_station, mig 79).
 *
 * Ruteo de ítems a estación por categoryIds — mismo modelo que
 * printer_binding (comodín [] = atiende todo). Ver context/24-orders-module-plan.md.
 */
final class StationService
{
    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(string $companyId, ?string $outletId = null): array
    {
        $where  = ['companyid = ?', 'status = 1'];
        $params = [$companyId];
        if ($outletId !== null && $outletId !== '') {
            $where[]  = '(outletid = ? OR outletid IS NULL)';
            $params[] = $outletId;
        }
        $rs = $this->db->Execute(
            'SELECT * FROM order_station WHERE ' . implode(' AND ', $where) . ' ORDER BY sort ASC, name ASC',
            $params
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM order_station WHERE stationid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    /**
     * @param array{outletId?:string, name:string, categoryIds?:list<string>, sort?:int} $data
     * @return string stationId
     */
    public function create(string $companyId, array $data): string
    {
        $name        = trim((string) ($data['name'] ?? ''));
        $outletId    = !empty($data['outletId']) ? (string) $data['outletId'] : null;
        $categoryIds = $this->validateCategoryIds($companyId, (array) ($data['categoryIds'] ?? []));
        $sort        = (int) ($data['sort'] ?? 0);

        if ($name === '') {
            throw new \InvalidArgumentException('name requerido');
        }
        if ($outletId !== null) {
            $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
            if (!$outlet) {
                throw new \InvalidArgumentException('outletId inválido para este tenant');
            }
        }

        $rs = $this->db->Execute(
            "INSERT INTO order_station (stationid, companyid, outletid, name, categoryids, sort)
             VALUES (gen_random_uuid(), ?, ?, ?, ?::jsonb, ?)
             RETURNING stationid",
            [$companyId, $outletId, $name, json_encode($categoryIds), $sort]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear la estación');
        }
        $id = (string) ($rs->fields['stationid'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('No se pudo crear la estación');
        }
        return $id;
    }

    public function update(string $companyId, string $id, array $data): array
    {
        $existing = $this->find($companyId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Estación no encontrada');
        }

        $name        = array_key_exists('name', $data) ? trim((string) $data['name']) : $existing['name'];
        $categoryIds = array_key_exists('categoryIds', $data)
            ? $this->validateCategoryIds($companyId, (array) $data['categoryIds'])
            : $existing['categoryIds'];
        $sort = array_key_exists('sort', $data) ? (int) $data['sort'] : $existing['sort'];

        if ($name === '') {
            throw new \InvalidArgumentException('name no puede quedar vacío');
        }

        $ok = $this->db->Execute(
            'UPDATE order_station SET name = ?, categoryids = ?::jsonb, sort = ? WHERE stationid = ? AND companyid = ?',
            [$name, json_encode($categoryIds), $sort, $id, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar la estación');
        }
        return $this->find($companyId, $id) ?? [];
    }

    public function delete(string $companyId, string $id): void
    {
        $existing = $this->find($companyId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Estación no encontrada');
        }
        // Soft-delete (status=0): pos_order_item.stationid referencia
        // históricamente esta fila, no queremos romper reportes/joins viejos.
        $ok = $this->db->Execute(
            'UPDATE order_station SET status = 0 WHERE stationid = ? AND companyid = ?',
            [$id, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo eliminar la estación');
        }
    }

    /** @return list<string> */
    private function validateCategoryIds(string $companyId, array $categoryIds): array
    {
        $ids = array_values(array_unique(array_map('strval', $categoryIds)));
        if ($ids === []) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rs = $this->db->Execute(
            "SELECT categoryId FROM category WHERE companyId = ? AND categoryId IN ($placeholders)",
            array_merge([$companyId], $ids)
        );
        $valid = [];
        if ($rs !== false) {
            foreach ($rs->GetRows() as $row) {
                $valid[] = (string) ($row['categoryid'] ?? $row['categoryId']);
            }
        }
        $invalid = array_diff($ids, $valid);
        if ($invalid !== []) {
            throw new \InvalidArgumentException('categoryIds inválidos para este tenant: ' . implode(', ', $invalid));
        }
        return $ids;
    }

    private function present(array $row): array
    {
        $raw = $row['categoryids'] ?? '[]';
        $ids = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);

        return [
            'id'          => (string) ($row['stationid'] ?? ''),
            'companyId'   => (string) ($row['companyid'] ?? ''),
            'outletId'    => $row['outletid'] ?? null,
            'name'        => (string) ($row['name'] ?? ''),
            'categoryIds' => array_map('strval', $ids),
            'sort'        => (int) ($row['sort'] ?? 0),
            'createdAt'   => $row['created_at'] ?? null,
        ];
    }
}
