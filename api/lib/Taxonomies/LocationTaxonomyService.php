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
            'SELECT 1 FROM outlet WHERE outletid = ? AND companyid = ?',
            [$outletId, $companyId]
        );
        // Ya NO se confunde un error de BD con "no encontrado" (era un 422
        // mentiroso): desde 2026-08-22 el wrapper LANZA. El `=== false` de acá
        // solo se alcanza con el kill-switch DB_THROW_ON_ERROR apagado, y ahí
        // se reporta como error, no como outlet inexistente.
        if ($rs === false) {
            throw new \RuntimeException('Error de BD al validar el outlet');
        }
        if (count($rs->GetRows()) === 0) {
            throw new \InvalidArgumentException('Outlet no encontrado');
        }

        // INSERT con RETURNING
        $rs = $this->db->Execute(
            'INSERT INTO taxonomy (taxonomyid, companyid, taxonomytype, outletid, taxonomyname)
             VALUES (gen_random_uuid(), ?, \'location\', ?, ?)
             RETURNING taxonomyid',
            [$companyId, $outletId, $name]
        );
        // Con el wrapper lanzando, este guard solo se alcanza con el kill-switch
        // DB_THROW_ON_ERROR apagado; en operación normal llega la
        // DbQueryException con la causa real de PG, más útil que este mensaje.
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
            'UPDATE taxonomy SET taxonomyname = ?
             WHERE taxonomyid = ? AND companyid = ? AND taxonomytype = \'location\'',
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
        // Guard FK: verificar que no haya items asignados a este depósito.
        // itemLocation sin comillas a propósito: la tabla se creó SIN quotes
        // → Postgres la plegó a minúsculas (itemlocation). Citarla como
        // "itemLocation" exige match exacto de mayúsculas y Postgres tira
        // "relation does not exist" (mismo bug documentado en detalle en
        // ReturnService::create(), clase itemSold/itemsold).
        $rs = $this->db->Execute(
            'SELECT COUNT(*) AS cnt FROM itemLocation WHERE locationid = ? AND companyid = ?',
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
            'DELETE FROM taxonomy WHERE taxonomyid = ? AND companyid = ? AND taxonomytype = \'location\'',
            [$id, $companyId]
        );

        return ['blocked' => false];
    }
}
