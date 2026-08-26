<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * LocationService — gestiona en qué depósitos vive un item (tabla `itemLocation`).
 *
 * Modelo: un item puede vivir en N depósitos a través de N outlets. Por cada
 * outlet, uno de esos depósitos es el "default" — el que se usa para venta
 * en mostrador o producción cuando no se especifica destino explícito.
 *
 * - `itemLocation(itemId, locationId)` es UNIQUE (no dup)
 * - `(itemId, outletId) WHERE isDefault = TRUE` es UNIQUE parcial:
 *   solo un default por sucursal.
 *
 * Schema: ver database/migrations/postgres/05_item_location.sql
 *
 * Port FIEL de panel/lib/items/LocationService.php (Fase 2 del desacople de /panel).
 * Cambios: namespace, `final`, `declare(strict_types=1)`. SQL y semántica idénticos.
 */
final class LocationService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Lista los depósitos donde vive el item en un outlet específico.
     * Retorna array de filas con: locationId, isDefault, taxonomyName.
     */
    public function listForItemInOutlet(string $itemId, string $outletId): array
    {
        $sql = "SELECT il.locationId, il.isDefault, t.taxonomyName
                  FROM itemLocation il
                  JOIN taxonomy t ON t.taxonomyId = il.locationId
                 WHERE il.itemId = ? AND il.outletId = ?
                 ORDER BY il.isDefault DESC, t.taxonomyName ASC";
        // Sin fallback silencioso a `[]`: un error de SQL acá hacía que el item
        // pareciera no tener depósitos, y de ahí sale de qué depósito descuenta
        // stock una venta. El wrapper lanza (DbQueryException) y el error se ve.
        $rs = $this->db->Execute($sql, [$itemId, $outletId]);
        if ($rs === false) {
            // Solo alcanzable con el kill-switch DB_THROW_ON_ERROR=false (modo
            // incendio): ahí el wrapper vuelve a devolver `false` en vez de
            // lanzar. Se loguea para que la degradación no sea invisible.
            error_log('[LocationService] listForItemInOutlet: la query falló (DB_THROW_ON_ERROR apagado) — item ' . $itemId);
            return [];
        }
        return $rs->GetRows();
    }

    /**
     * Lista TODOS los depósitos del item (todos los outlets).
     *
     * `companyId` obligatorio: sin él, esta lectura devolvía los nombres de
     * sucursal/depósito de CUALQUIER ítem del sistema con solo pasar su itemId
     * (leak cross-tenant). El endpoint (items.php) ya valida pertenencia antes
     * de llamar, pero el filtro va también acá — el aislamiento no depende de
     * que el caller se acuerde.
     */
    public function listForItem(string $itemId, string $companyId): array
    {
        $sql = "SELECT il.locationId, il.outletId, il.isDefault, t.taxonomyName, o.outletName
                  FROM itemLocation il
                  JOIN taxonomy t ON t.taxonomyId = il.locationId
                  JOIN outlet   o ON o.outletId   = il.outletId
                 WHERE il.itemId = ? AND il.companyId = ?
                 ORDER BY o.outletName, il.isDefault DESC, t.taxonomyName";
        $rs = $this->db->Execute($sql, [$itemId, $companyId]);
        if ($rs === false) {
            // Solo alcanzable con el kill-switch DB_THROW_ON_ERROR=false (modo
            // incendio): ahí el wrapper vuelve a devolver `false` en vez de
            // lanzar. Se loguea para que la degradación no sea invisible.
            error_log('[LocationService] listForItem: la query falló (DB_THROW_ON_ERROR apagado) — item ' . $itemId);
            return [];
        }
        return $rs->GetRows();
    }

    /**
     * Resuelve el depósito a usar para una venta o producción en un outlet.
     *
     * Reglas:
     *   - Si el item tiene N depósitos en ese outlet, usa el isDefault.
     *   - Si tiene 1 solo, usa ese (sea o no default).
     *   - Si no tiene ninguno, retorna null (el caller decide cómo manejar).
     *
     * @return ?string locationId o null
     */
    public function resolveFor(string $itemId, string $outletId): ?string
    {
        $sql = "SELECT locationId FROM itemLocation
                 WHERE itemId = ? AND outletId = ?
                 ORDER BY isDefault DESC
                 LIMIT 1";
        $rs = $this->db->Execute($sql, [$itemId, $outletId]);
        if ($rs === false || $rs->EOF) return null;
        return $rs->fields['locationid'] ?? null;
    }

    /**
     * Asigna el item a un depósito. Si ya estaba asignado, no hace nada (UNIQUE).
     * outletId se resuelve vía la taxonomy.
     */
    public function attach(string $itemId, string $locationId, string $companyId, bool $isDefault = false): bool
    {
        $outletId = $this->resolveOutletForLocation($locationId);
        if ($outletId === null) return false;

        if ($isDefault) {
            // Asegurar que solo este sea el default del outlet
            $this->db->Execute(
                "UPDATE itemLocation SET isDefault = FALSE WHERE itemId = ? AND outletId = ? AND locationId <> ? AND companyId = ?",
                [$itemId, $outletId, $locationId, $companyId]
            );
        }

        $sql = "INSERT INTO itemLocation (itemId, locationId, outletId, isDefault, companyId)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (itemId, locationId) DO UPDATE
                  SET isDefault = EXCLUDED.isDefault";
        return $this->db->Execute($sql, [$itemId, $locationId, $outletId, $isDefault, $companyId]) !== false;
    }

    /**
     * Quita la asignación de un item a un depósito.
     *
     * `companyId` obligatorio: sin él, el DELETE por itemId a secas borraba las
     * asignaciones de depósito de un ítem de OTRA empresa (leak cross-tenant vía
     * `syncForItem`). Ver el guard del endpoint + el docblock de listForItem.
     */
    public function detach(string $itemId, string $locationId, string $companyId): bool
    {
        return $this->db->Execute(
            "DELETE FROM itemLocation WHERE itemId = ? AND locationId = ? AND companyId = ?",
            [$itemId, $locationId, $companyId]
        ) !== false;
    }

    /**
     * Reemplaza la lista completa de depósitos del item por la que viene del form.
     *
     * @param array $locationIds  IDs de taxonomy(location) que deben quedar asignados.
     * @param ?string $defaultLocationId  De esos IDs, cuál marcar como default por outlet.
     */
    public function syncForItem(string $itemId, string $companyId, array $locationIds, ?string $defaultLocationId = null): void
    {
        $existing = $this->db->Execute(
            "SELECT locationId FROM itemLocation WHERE itemId = ? AND companyId = ?",
            [$itemId, $companyId]
        );
        $current = [];
        if ($existing !== false) {
            foreach ($existing->GetRows() as $r) $current[] = $r['locationid'];
        }

        $toAdd    = array_diff($locationIds, $current);
        $toRemove = array_diff($current, $locationIds);

        foreach ($toRemove as $loc) {
            $this->detach($itemId, $loc, $companyId);
        }
        foreach ($toAdd as $loc) {
            $this->attach($itemId, $loc, $companyId, $loc === $defaultLocationId);
        }
        // Re-aplicar default si ya estaba presente y el form lo cambió
        if ($defaultLocationId !== null && !in_array($defaultLocationId, $toAdd)) {
            $this->setDefault($itemId, $defaultLocationId, $companyId);
        }
    }

    /**
     * Marca un depósito como default para su outlet. Desmarca los demás del mismo outlet.
     */
    public function setDefault(string $itemId, string $locationId, string $companyId): bool
    {
        $outletId = $this->resolveOutletForLocation($locationId);
        if ($outletId === null) return false;

        $this->db->Execute(
            "UPDATE itemLocation SET isDefault = FALSE WHERE itemId = ? AND outletId = ? AND companyId = ?",
            [$itemId, $outletId, $companyId]
        );
        return $this->db->Execute(
            "UPDATE itemLocation SET isDefault = TRUE WHERE itemId = ? AND locationId = ? AND companyId = ?",
            [$itemId, $locationId, $companyId]
        ) !== false;
    }

    /**
     * Helper: dado un locationId (taxonomy), devuelve su outletId.
     */
    private function resolveOutletForLocation(string $locationId): ?string
    {
        $rs = $this->db->Execute(
            "SELECT outletId FROM taxonomy WHERE taxonomyId = ? AND taxonomyType = 'location' LIMIT 1",
            [$locationId]
        );
        if ($rs === false || $rs->EOF) return null;
        return $rs->fields['outletid'] ?? null;
    }
}
