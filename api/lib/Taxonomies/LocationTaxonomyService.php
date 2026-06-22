<?php
declare(strict_types=1);

namespace Punto\Api\Taxonomies;

final class LocationTaxonomyService
{
    public function __construct(private $db) {}

    public function create(string $companyId, string $outletId, string $name): array
    {
        // Validar outletId pertenece a companyId
        $rs = $this->db->Execute(
            'SELECT 1 FROM outlet WHERE "outletId" = ? AND "companyId" = ?',
            [$outletId, $companyId]
        );
        if ($rs === false || count($rs->GetRows()) === 0) {
            throw new \InvalidArgumentException('Outlet no encontrado');
        }

        // INSERT con RETURNING
        $rs = $this->db->Execute(
            'INSERT INTO taxonomy ("taxonomyId", "companyId", "taxonomyType", "outletId", "taxonomyName")
             VALUES (gen_random_uuid(), ?, \'location\', ?, ?)
             RETURNING "taxonomyId"',
            [$companyId, $outletId, $name]
        );
        if ($rs === false) {
            throw new \RuntimeException('Error al crear depósito');
        }
        $rows = $rs->GetRows();
        $id = (string) ($rows[0]['taxonomyid'] ?? '');

        return ['id' => $id, 'name' => $name, 'outletId' => $outletId];
    }

    public function update(string $companyId, string $id, string $name): bool
    {
        $rs = $this->db->Execute(
            'UPDATE taxonomy SET "taxonomyName" = ?
             WHERE "taxonomyId" = ? AND "companyId" = ? AND "taxonomyType" = \'location\'',
            [$name, $id, $companyId]
        );
        if ($rs === false) {
            return false;
        }
        return $this->db->Affected_Rows() > 0;
    }

    /**
     * Elimina un depósito. Bloquea si hay items asignados en itemLocation.
     *
     * @return array{ blocked: bool, items?: int }
     */
    public function delete(string $companyId, string $id): array
    {
        // Guard FK: verificar que no haya items asignados a este depósito
        $rs = $this->db->Execute(
            'SELECT COUNT(*) AS cnt FROM "itemLocation" WHERE "locationId" = ? AND "companyId" = ?',
            [$id, $companyId]
        );
        if ($rs !== false) {
            $rows = $rs->GetRows();
            $cnt  = (int) ($rows[0]['cnt'] ?? 0);
            if ($cnt > 0) {
                return ['blocked' => true, 'items' => $cnt];
            }
        }

        $this->db->Execute(
            'DELETE FROM taxonomy WHERE "taxonomyId" = ? AND "companyId" = ? AND "taxonomyType" = \'location\'',
            [$id, $companyId]
        );

        return ['blocked' => false];
    }
}
