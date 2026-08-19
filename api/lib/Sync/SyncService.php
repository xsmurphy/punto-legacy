<?php
declare(strict_types=1);

namespace Punto\Api\Sync;

use function Punto\Api\Items\buildItemsSelectSql;
use function Punto\Api\Items\presentItem;
use function Punto\Api\Items\outletVisibilityClause;

/**
 * SyncService — sync incremental del POS (context/43-sync-incremental.md).
 *
 * Reemplaza, al reconectar/arrancar, la descarga completa del catálogo por
 * "traeme lo que cambió desde tal fecha + los ids que borraron". Tres
 * secciones, cada una con su propia estrategia de watermark:
 *
 *   - `items`/`customers`: watermark DERIVADO (`MAX(updated_at)`, indexado)
 *     de la propia tabla — no puede desincronizarse de la realidad porque
 *     SE COMPUTA de la realidad. Delta por fila + lápidas (`deleted_row`,
 *     mig 138) para los borrados duros.
 *   - `settings`: watermark MANTENIDO (`company.config.settingsLastUpdate`,
 *     bumpeado desde `syncSectionAfterMutation()` en bootstrap.php) porque
 *     el bundle (outlet/register/tax/category/brand/tag/payment-method/
 *     printer_binding/user) es de cardinalidad baja — no necesita delta por
 *     fila, un refetch completo cuando está stale es barato y correcto.
 */
final class SyncService
{
    /**
     * Ventana de retención de `deleted_row` (mig 138, job pg_cron
     * `purge-deleted-row`) — 90 días, decisión del owner. Coordinada A MANO
     * con el SQL de la migración — pg_cron no puede leer una constante PHP.
     * Si el `since` del cliente es más viejo que esto, las lápidas de ese
     * rango pueden haber sido purgadas — NUNCA confiar en una cobertura
     * parcial, forzar `full`. Esta regla y la ventana son la MISMA decisión:
     * sin ella, un borrado cuya lápida ya se purgó queda como producto
     * fantasma para siempre en un dispositivo que estuvo offline más de 90
     * días.
     */
    public const TOMBSTONE_RETENTION_DAYS = 90;

    /**
     * Umbral de borde (Alcance §5): si el delta trae más filas que esto,
     * recargar todo es más barato que aplicar un merge gigante fila por
     * fila en el store del front. No es el camino normal — un tenant con
     * 5000 items nunca debería acercarse a este número en un delta real
     * (implicaría que CASI todo el catálogo cambió desde el último sync).
     */
    public const MAX_REASONABLE_ROWS = 20000;

    /** @var mixed */
    private $db;

    /** @param mixed $db */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Watermarks de las 3 secciones + la hora del server. El POS SIEMPRE usa
     * `serverTime` (nunca `Date.now()` del dispositivo) como marca de agua
     * después de un sync exitoso — un reloj de tablet corrido perdería
     * actualizaciones para siempre o pediría todo cada vez.
     *
     * Mismo formato que `TODAY` (`Y-m-d H:i:s`, hora del server PHP) — es el
     * formato que ya usa CADA escritura de `updated_at` en el codebase
     * (`ItemService::update()`, `ContactService::update()`, etc.). El front
     * lo trata como opaco: lo guarda y lo reenvía tal cual como `since` en
     * el próximo sync, nunca lo parsea.
     */
    public function watermarks(string $companyId): array
    {
        return [
            'items'      => $this->maxUpdatedAt('item', $companyId, null),
            'customers'  => $this->maxUpdatedAt('contact', $companyId, 'type = 1'),
            'settings'   => $this->settingsWatermark($companyId),
            'serverTime' => \TODAY,
        ];
    }

    private function maxUpdatedAt(string $table, string $companyId, ?string $extraWhere): array|string|null
    {
        $where = 'companyId = ?';
        if ($extraWhere !== null) {
            $where .= ' AND ' . $extraWhere;
        }
        $rs = $this->db->Execute("SELECT MAX(updated_at) AS m FROM {$table} WHERE {$where}", [$companyId]);
        if ($rs === false || $rs->EOF) return null;
        return $rs->fields['m'] ?? null;
    }

    private function settingsWatermark(string $companyId): ?string
    {
        $rs = $this->db->Execute('SELECT config FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
        if ($rs === false || $rs->EOF) return null;
        $raw = $rs->fields['config'] ?? null;
        if (!is_string($raw) || $raw === '') return null;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return null;
        return isset($decoded['settingsLastUpdate']) ? (string) $decoded['settingsLastUpdate'] : null;
    }

    /**
     * `$since` demasiado viejo (o ausente) → el caller debe hacer un full
     * sync, no confiar en un delta que puede no cubrir todo el rango.
     */
    public function isTooStale(?string $since): bool
    {
        if ($since === null || $since === '') return true;
        $ts = strtotime($since);
        if ($ts === false) return true;
        $horizon = strtotime('-' . self::TOMBSTONE_RETENTION_DAYS . ' days');
        return $ts < $horizon;
    }

    /**
     * Delta de items: filas cambiadas desde `$since` + ids borrados desde
     * `$since` (tabla de lápidas). `full=true` (sin query de datos) cuando
     * `$since` es null o excede la retención de lápidas.
     *
     * `$outletId`: mismo criterio que `outletVisibilityClause()` en
     * `ItemsQuery.php` — pasar el outlet del device (pos-app) para que el
     * delta NUNCA reintroduzca en el cache local un ítem de otra sucursal
     * que el bootstrap ya había excluido (ej. si ese ítem se edita después
     * en otra caja, el `updated_at` lo haría entrar por delta si no se
     * filtrara acá también). `null` para panel: sin restricción.
     */
    public function itemsDelta(string $companyId, ?string $since, ?string $outletId = null): array
    {
        if ($this->isTooStale($since)) {
            return ['items' => [], 'deletedIds' => [], 'full' => true, 'serverTime' => \TODAY];
        }

        $whereSql = 'i.companyId = ? AND COALESCE(i.updated_at, i.itemDate) > ?';
        $params   = [$companyId, $since];
        [$outletClause, $outletParams] = outletVisibilityClause($outletId);
        if ($outletClause !== '') {
            $whereSql .= " AND {$outletClause}";
            $params    = array_merge($params, $outletParams);
        }

        $sql = buildItemsSelectSql($whereSql, 'ORDER BY i.updated_at ASC NULLS FIRST');
        $rs = $this->db->Execute($sql, $params);
        $items = [];
        if ($rs !== false) {
            foreach ($rs->GetRows() as $row) {
                $items[] = presentItem(_flattenJsonb($row));
            }
        }

        $deletedIds = $this->deletedIdsSince('item', $companyId, $since);

        if (count($items) + count($deletedIds) > self::MAX_REASONABLE_ROWS) {
            return ['items' => [], 'deletedIds' => [], 'full' => true, 'serverTime' => \TODAY];
        }

        return ['items' => $items, 'deletedIds' => $deletedIds, 'full' => false, 'serverTime' => \TODAY];
    }

    /**
     * Delta de clientes — mismo criterio que `itemsDelta()`. `type = 1`
     * (cliente): mismo filtro que el resto del catálogo de clientes del POS
     * (`ContactService::TYPE_CUSTOMER`); un proveedor editado no cuenta acá.
     */
    public function customersDelta(string $companyId, ?string $since, \Punto\Api\Contacts\ContactService $contactService): array
    {
        if ($this->isTooStale($since)) {
            return ['customers' => [], 'deletedIds' => [], 'full' => true, 'serverTime' => \TODAY];
        }

        $customers = $contactService->manyUpdatedSince(1, $companyId, $since);
        $deletedIds = $this->deletedIdsSince('contact', $companyId, $since);

        if (count($customers) + count($deletedIds) > self::MAX_REASONABLE_ROWS) {
            return ['customers' => [], 'deletedIds' => [], 'full' => true, 'serverTime' => \TODAY];
        }

        return ['customers' => $customers, 'deletedIds' => $deletedIds, 'full' => false, 'serverTime' => \TODAY];
    }

    /**
     * Ids de `$entity` borrados desde `$since` (tabla de lápidas, mig 138).
     * companyId SIEMPRE del caller (aislamiento multi-tenant) — nunca se
     * confía en el `since` del body para acotar tenant, solo fecha.
     */
    private function deletedIdsSince(string $entity, string $companyId, string $since): array
    {
        $rs = $this->db->Execute(
            'SELECT "rowId" FROM deleted_row WHERE "companyId" = ? AND entity = ? AND deleted_at > ?',
            [$companyId, $entity, $since]
        );
        if ($rs === false) return [];
        $ids = [];
        foreach ($rs->GetRows() as $row) {
            $ids[] = $row['rowid'] ?? $row['rowId'] ?? null;
        }
        return array_values(array_filter($ids));
    }
}
