<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

/**
 * SpaceSectorService — CRUD de sectores del salón/local (space_sector, mig 80).
 *
 * Zonas de agrupación de espacios (Terraza, Salón, Barra — o Planta alta,
 * Cabinas, según el rubro). Patrón de service calcado de `StationService`
 * (api/lib/Orders/StationService.php) — mismo escalón de complejidad, no
 * requiere estado derivado.
 */
final class SpaceSectorService
{
    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int,array<string,mixed>> siempre no-vacío — ensureDefaultSector()
     *         garantiza al menos un sector activo por outlet.
     */
    public function list(string $companyId, string $outletId): array
    {
        $this->ensureDefaultSector($companyId, $outletId);
        $rs = $this->db->Execute(
            'SELECT * FROM space_sector WHERE companyid = ? AND outletid = ? AND status = 1 ORDER BY sort ASC, name ASC',
            [$companyId, $outletId]
        );
        if ($rs === false) return [];
        $out = [];
        foreach ($rs->GetRows() as $row) {
            $out[] = $this->present($row);
        }
        return $out;
    }

    /**
     * Todo espacio SIEMPRE pertenece a un sector (space.sectorid NOT NULL,
     * mig 82) — no existe "espacio sin sector". Si el outlet no tiene NINGÚN
     * sector activo, crea "Salón" como default. Idempotente: llamado desde
     * `list()` y antes de cualquier create/bulkCreate sin sectorId explícito
     * en SpaceService — nunca crea un segundo default si ya hay uno activo.
     *
     * @return string sectorId del primer sector activo (existente o recién creado)
     */
    public function ensureDefaultSector(string $companyId, string $outletId): string
    {
        $existing = ncmExecute(
            'SELECT sectorid FROM space_sector
              WHERE companyid = ? AND outletid = ? AND status = 1
              ORDER BY sort ASC, created_at ASC LIMIT 1',
            [$companyId, $outletId]
        );
        if ($existing) {
            return (string) $existing['sectorid'];
        }

        $rs = $this->db->Execute(
            'INSERT INTO space_sector (sectorid, companyid, outletid, name, sort)
             VALUES (gen_random_uuid(), ?, ?, ?, 0)
             RETURNING sectorid',
            [$companyId, $outletId, 'Salón']
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear el sector default');
        }
        return (string) $rs->fields['sectorid'];
    }

    public function find(string $companyId, string $id): ?array
    {
        $rs = $this->db->Execute(
            'SELECT * FROM space_sector WHERE sectorid = ? AND companyid = ? LIMIT 1',
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
            'INSERT INTO space_sector (sectorid, companyid, outletid, name, sort)
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
            'UPDATE space_sector SET name = ?, sort = ? WHERE sectorid = ? AND companyid = ?',
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
        // Soft-delete: los espacios existentes conservan sectorid (histórico/reportes);
        // el front debe dejar de listar el sector inactivo como destino nuevo.
        $ok = $this->db->Execute(
            'UPDATE space_sector SET status = 0 WHERE sectorid = ? AND companyid = ?',
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
