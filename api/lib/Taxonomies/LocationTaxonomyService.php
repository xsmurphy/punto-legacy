<?php
declare(strict_types=1);

namespace Punto\Api\Taxonomies;

/**
 * Depósitos (`taxonomy` con taxonomytype='location', atados a la sucursal por
 * `taxonomy.outletid`).
 *
 * REGLA (owner, 2026-08-24): toda sucursal tiene SÍ O SÍ un depósito, y
 * exactamente uno de ellos es el POR DEFECTO — "el stock tiene que estar en un
 * lugar físico, no puede estar en el aire". El marcador es
 * `taxonomyextra = {"isDefault": true}` (mismo patrón que los roles seed de
 * `RoleService`), y la unicidad la garantiza el índice
 * `uq_taxonomy_location_default` (mig 165), no la disciplina de este código.
 *
 * `ensureDefault()` es el ÚNICO creador del depósito por defecto: lo llaman
 * los dos caminos de producción que dan de alta una sucursal
 * (`OutletsService::create()` y `Auth\SignupService`). Agregar un tercer
 * camino de alta de sucursal obliga a llamarlo también.
 */
final class LocationTaxonomyService
{
    /** Marcador del depósito por defecto dentro de `taxonomyextra`. */
    private const DEFAULT_EXTRA = '{"isDefault": true}';

    public function __construct(private $db) {}

    /**
     * El depósito por defecto de una sucursal, o null si no tiene ninguno.
     *
     * El filtro va por la función `fn_taxonomy_is_default_location` (mig 165)
     * y no por un `taxonomyextra::jsonb ->> 'isDefault'` inline para que la
     * definición de "por defecto" viva en UN solo lugar — el mismo que usa el
     * índice único y el que usan los lectores del ledger.
     *
     * @return array{id: string, name: string}|null
     */
    public function defaultFor(string $companyId, string $outletId): ?array
    {
        $rs = $this->db->Execute(
            "SELECT taxonomyid, taxonomyname
               FROM taxonomy
              WHERE outletid = ? AND companyid = ? AND taxonomytype = 'location'
                AND fn_taxonomy_is_default_location(taxonomytype, taxonomyextra)
              LIMIT 1",
            [$outletId, $companyId]
        );
        if ($rs === false) { return null; }
        $rows = $rs->GetRows();
        if (count($rows) === 0) { return null; }

        return [
            'id'   => (string) $rows[0]['taxonomyid'],
            'name' => (string) ($rows[0]['taxonomyname'] ?? ''),
        ];
    }

    /**
     * Garantiza que la sucursal tenga su depósito por defecto. Idempotente.
     *
     * Tres casos, en orden:
     *   1. Ya hay uno marcado → se devuelve, no se toca nada.
     *   2. Hay depósitos pero ninguno marcado (sucursal anterior a la mig 165,
     *      o alguien borró el marcado) → se MARCA uno existente en vez de
     *      crear uno nuevo: el comercio ya opera esos depósitos y uno extra
     *      sería ruido.
     *   3. No hay ninguno → se crea.
     *
     * DEBE llamarse dentro de la transacción que crea la sucursal: una
     * sucursal sin depósito no es un estado válido, así que si esto falla, la
     * sucursal no se crea.
     *
     * @return array{id: string, name: string}
     */
    public function ensureDefault(string $companyId, string $outletId, string $outletName = ''): array
    {
        $existente = $this->defaultFor($companyId, $outletId);
        if ($existente !== null) {
            return $existente;
        }

        // Caso 2 — hay depósitos sin marcar. Desempate por `ctid`: `taxonomy`
        // no tiene columna de tiempo y sus UUID son v4 random, así que
        // `ORDER BY taxonomyid` NO da orden de creación (memoria del
        // proyecto). `ctid` aproxima el orden físico de inserción; lo que
        // importa acá es que la elección sea determinística.
        $rs = $this->db->Execute(
            "SELECT taxonomyid, taxonomyname
               FROM taxonomy
              WHERE outletid = ? AND companyid = ? AND taxonomytype = 'location'
              ORDER BY ctid
              LIMIT 1",
            [$outletId, $companyId]
        );
        $rows = ($rs === false) ? [] : $rs->GetRows();
        if (count($rows) > 0) {
            $id  = (string) $rows[0]['taxonomyid'];
            $upd = $this->db->Execute(
                "UPDATE taxonomy
                    SET taxonomyextra = (
                            COALESCE(NULLIF(btrim(COALESCE(taxonomyextra, '')), '')::jsonb, '{}'::jsonb)
                            || ?::jsonb
                        )::text
                  WHERE taxonomyid = ?",
                [self::DEFAULT_EXTRA, $id]
            );
            // Fail-closed: con el kill-switch DB_THROW_ON_ERROR apagado el
            // wrapper devuelve `false` en vez de lanzar. Sin este guard,
            // ensureDefault() reportaría éxito sobre una transacción PG ya
            // abortada y el caller seguiría escribiendo contra ella (25P02).
            if ($upd === false) {
                throw new \RuntimeException('No se pudo marcar el depósito por defecto de la sucursal');
            }
            return ['id' => $id, 'name' => (string) ($rows[0]['taxonomyname'] ?? '')];
        }

        // Caso 3 — crear. El nombre sale de la sucursal porque
        // `uq_taxonomy_company_type_name` (mig 38) es UNIQUE sobre
        // (companyid, taxonomytype, lower(taxonomyname)): un literal fijo
        // "Depósito principal" reventaría al crear la SEGUNDA sucursal de la
        // misma company. Además es lo que el operador espera leer en el
        // selector.
        if ($outletName === '') {
            $rsName = $this->db->Execute(
                'SELECT outletname FROM outlet WHERE outletid = ? AND companyid = ?',
                [$outletId, $companyId]
            );
            $nameRows  = ($rsName === false) ? [] : $rsName->GetRows();
            $outletName = (string) ($nameRows[0]['outletname'] ?? '');
        }

        $nombre = $this->freeLocationName($companyId, $outletId, $outletName);

        $rs = $this->db->Execute(
            "INSERT INTO taxonomy (taxonomyid, companyid, taxonomytype, outletid, taxonomyname, taxonomyextra)
             VALUES (gen_random_uuid(), ?, 'location', ?, ?, ?)
             RETURNING taxonomyid",
            [$companyId, $outletId, $nombre, self::DEFAULT_EXTRA]
        );
        if ($rs === false) {
            throw new \RuntimeException('No se pudo crear el depósito por defecto de la sucursal');
        }
        $rows = $rs->GetRows();

        return ['id' => (string) ($rows[0]['taxonomyid'] ?? ''), 'name' => $nombre];
    }

    /**
     * Nombre libre para el depósito por defecto, respetando
     * `uq_taxonomy_company_type_name`. Si "Depósito <sucursal>" ya está tomado
     * en la company (dos sucursales homónimas), desambigua con el prefijo del
     * outletId — determinístico y estable.
     */
    private function freeLocationName(string $companyId, string $outletId, string $outletName): string
    {
        $base = 'Depósito ' . ($outletName !== '' ? $outletName : 'principal');

        $rs = $this->db->Execute(
            "SELECT 1 FROM taxonomy
              WHERE companyid = ? AND taxonomytype = 'location'
                AND lower(taxonomyname) = lower(?)
              LIMIT 1",
            [$companyId, $base]
        );
        $tomado = ($rs !== false) && count($rs->GetRows()) > 0;

        return $tomado
            ? $base . ' (' . substr($outletId, 0, 8) . ')'
            : $base;
    }

    /**
     * Mueve la marca de "por defecto" a otro depósito de la MISMA sucursal.
     *
     * Existe porque el default no se puede borrar (ver `delete()`): sin esta
     * acción, el depósito por defecto quedaría indeleteable para siempre y el
     * mensaje del 409 prometería algo imposible.
     *
     * Las dos escrituras van en una transacción: entre el UPDATE que limpia el
     * anterior y el que marca el nuevo, la sucursal no tiene default. El
     * índice único `uq_taxonomy_location_default` cubre la carrera de dos
     * requests simultáneos (el segundo choca en vez de dejar dos defaults).
     *
     * @return bool false si el depósito no existe / no es del tenant.
     */
    public function setDefault(string $companyId, string $id): bool
    {
        $rs = $this->db->Execute(
            "SELECT outletid FROM taxonomy
              WHERE taxonomyid = ? AND companyid = ? AND taxonomytype = 'location'",
            [$id, $companyId]
        );
        if ($rs === false) {
            throw new \RuntimeException('Error de BD al buscar el depósito');
        }
        $rows = $rs->GetRows();
        if (count($rows) === 0) { return false; }
        $outletId = (string) $rows[0]['outletid'];

        $this->db->StartTrans();

        // Limpia el marcador del default vigente de esa sucursal (si hay).
        $this->db->Execute(
            "UPDATE taxonomy
                SET taxonomyextra = (
                        COALESCE(NULLIF(btrim(COALESCE(taxonomyextra, '')), '')::jsonb, '{}'::jsonb)
                        - 'isDefault'
                    )::text
              WHERE outletid = ? AND companyid = ? AND taxonomytype = 'location'
                AND fn_taxonomy_is_default_location(taxonomytype, taxonomyextra)",
            [$outletId, $companyId]
        );

        $this->db->Execute(
            "UPDATE taxonomy
                SET taxonomyextra = (
                        COALESCE(NULLIF(btrim(COALESCE(taxonomyextra, '')), '')::jsonb, '{}'::jsonb)
                        || ?::jsonb
                    )::text
              WHERE taxonomyid = ? AND companyid = ?",
            [self::DEFAULT_EXTRA, $id, $companyId]
        );

        $failed = $this->db->HasFailedTrans();
        $this->db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo cambiar el depósito por defecto');
        }
        return true;
    }

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
     * Elimina un depósito. Bloquea si hay items asignados en itemLocation, y
     * bloquea SIEMPRE el depósito por defecto.
     *
     * El default no se borra porque la sucursal quedaría sin depósito y el
     * stock sin lugar físico donde estar (regla del owner). Para cambiarlo hay
     * que marcar otro como default primero.
     *
     * @return array{ blocked: bool, items?: int, reason?: string }
     */
    public function delete(string $companyId, string $id): array
    {
        $rsDef = $this->db->Execute(
            "SELECT 1 FROM taxonomy
              WHERE taxonomyid = ? AND companyid = ? AND taxonomytype = 'location'
                AND fn_taxonomy_is_default_location(taxonomytype, taxonomyextra)
              LIMIT 1",
            [$id, $companyId]
        );
        // Fail-CLOSED: si la consulta falla no se puede afirmar que este
        // depósito NO sea el default, y borrarlo dejaría la sucursal sin
        // ninguno. Ante la duda, no se borra.
        if ($rsDef === false) {
            throw new \RuntimeException('No se pudo verificar si el depósito es el predeterminado');
        }
        if (count($rsDef->GetRows()) > 0) {
            return ['blocked' => true, 'reason' => 'default'];
        }

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
