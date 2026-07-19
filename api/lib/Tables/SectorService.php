<?php
declare(strict_types=1);

namespace Punto\Api\Tables;

/**
 * SectorService — CRUD de sectores del salón (table_sector, mig 80).
 *
 * Zonas de agrupación de mesas (Terraza, Salón, Barra). Patrón de service
 * calcado de `StationService` (api/lib/Orders/StationService.php) — mismo
 * escalón de complejidad, no requiere estado derivado.
 */
final class SectorService
{
    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /** @return array<int,array<string,mixed>> */
    public function list(string $companyId, string $outletId): array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM table_sector WHERE companyid = ? AND outletid = ? AND status = 1 ORDER BY sort ASC, name ASC',
            [$companyId, $outletId]
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
            'SELECT * FROM table_sector WHERE sectorid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );
        if ($rs === false || $rs->EOF) return null;
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        return $this->present($row);
    }

    /**
     * @param array{outletId:string, name:string, sort?:int} $data
     * @return string sectorId
     */
    public function create(string $companyId, array $data): string
    {
        $outletId = (string) ($data['outletId'] ?? '');
        $name     = trim((string) ($data['name'] ?? ''));
        $sort     = (int) ($data['sort'] ?? 0);

        if ($outletId === '') {
            throw new \InvalidArgumentException('outletId requerido');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('name requerido');
        }
        $outlet = ncmExecute('SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1', [$outletId, $companyId]);
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        $rs = $this->db->Execute(
            'INSERT INTO table_sector (sectorid, companyid, outletid, name, sort)
             VALUES (gen_random_uuid(), ?, ?, ?, ?)
             RETURNING sectorid',
            [$companyId, $outletId, $name, $sort]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear el sector');
        }
        $id = (string) ($rs->fields['sectorid'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('No se pudo crear el sector');
        }
        return $id;
    }

    public function update(string $companyId, string $id, array $data): array
    {
        $existing = $this->find($companyId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Sector no encontrado');
        }

        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $existing['name'];
        $sort = array_key_exists('sort', $data) ? (int) $data['sort'] : $existing['sort'];

        if ($name === '') {
            throw new \InvalidArgumentException('name no puede quedar vacío');
        }

        $ok = $this->db->Execute(
            'UPDATE table_sector SET name = ?, sort = ? WHERE sectorid = ? AND companyid = ?',
            [$name, $sort, $id, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo actualizar el sector');
        }
        return $this->find($companyId, $id) ?? [];
    }

    public function delete(string $companyId, string $id): void
    {
        $existing = $this->find($companyId, $id);
        if ($existing === null) {
            throw new \RuntimeException('Sector no encontrado');
        }
        // Soft-delete: las mesas existentes conservan sectorid (histórico/reportes);
        // el front debe dejar de listar el sector inactivo como destino nuevo.
        $ok = $this->db->Execute(
            'UPDATE table_sector SET status = 0 WHERE sectorid = ? AND companyid = ?',
            [$id, $companyId]
        );
        if ($ok === false) {
            throw new \RuntimeException('No se pudo eliminar el sector');
        }
    }

    private function present(array $row): array
    {
        return [
            'id'        => (string) ($row['sectorid'] ?? ''),
            'companyId' => (string) ($row['companyid'] ?? ''),
            'outletId'  => (string) ($row['outletid'] ?? ''),
            'name'      => (string) ($row['name'] ?? ''),
            'sort'      => (int) ($row['sort'] ?? 0),
            'createdAt' => $row['created_at'] ?? null,
        ];
    }
}
