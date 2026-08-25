<?php
declare(strict_types=1);

namespace Punto\Api\Sync;

use function Punto\Api\Items\buildItemsSelectSql;
use function Punto\Api\Items\presentItem;
use function Punto\Api\Items\outletVisibilityClause;
use function Punto\Api\Items\outletInvisibilityClause;

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

        // Un ítem al que le sacaron ESTA sucursal (mig 170, `item_outlet`) no
        // se borró: dejó de existir PARA ESTA CAJA. El trigger
        // `trg_item_outlet_touch_item` ya le bumpeó `updated_at`, así que cae
        // dentro de la ventana del delta — pero el filtro positivo de arriba
        // lo descarta, y sin este paso el device se quedaría con la copia
        // vieja en cache, vendible, para siempre.
        //
        // Para la caja el efecto es idéntico a un borrado (sacá este id de tu
        // catálogo), así que viaja por `deletedIds` y NO por un campo nuevo:
        // el cliente ya sabe procesarlo y no hay contrato que versionar.
        $deletedIds = array_values(array_unique(array_merge(
            $deletedIds,
            $this->itemsNoLongerVisibleTo($companyId, $since, $outletId)
        )));

        // Invariante del delta: NUNCA mandar un id en `items` y en `deletedIds`
        // a la vez. El cliente aplicaría los dos y el resultado dependería del
        // orden. Puede pasar de verdad: si a un ítem le sacan la sucursal y se
        // la devuelven dentro de la misma ventana, o si una lápida vieja de la
        // tabla `deleted_row` convive con un ítem re-creado con el mismo id.
        // Gana la presencia — si el ítem está visible AHORA, no se borra.
        $presentIds = [];
        foreach ($items as $it) {
            if (!empty($it['itemId'])) $presentIds[$it['itemId']] = true;
        }
        $deletedIds = array_values(array_filter(
            $deletedIds,
            static fn($id) => !isset($presentIds[$id])
        ));

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
            'SELECT rowid FROM deleted_row WHERE companyid = ? AND entity = ? AND deleted_at > ?',
            [$companyId, $entity, $since]
        );
        if ($rs === false) return [];
        $ids = [];
        foreach ($rs->GetRows() as $row) {
            $ids[] = $row['rowid'] ?? $row['rowId'] ?? null;
        }
        return array_values(array_filter($ids));
    }

    /**
     * Ids de ítems que cambiaron desde `$since` y que YA NO pertenecen a
     * `$outletId` (mig 170). Contraparte de la rama positiva de
     * `itemsDelta()`: mismo `companyId`, mismo `since`, criterio de
     * pertenencia negado vía `outletInvisibilityClause()`.
     *
     * Devuelve SOLO ids — no hace falta el payload de un ítem que la caja
     * tiene que descartar, y así el costo no depende de `buildItemsSelectSql()`
     * (que joinea impuestos/addons/imágenes para armar el `PosItem`).
     *
     * Sin `$outletId` (panel) no aplica: el panel no tiene un catálogo local
     * que podarle.
     *
     * VOLUMEN — el conjunto está acotado por los ítems EDITADOS en la ventana
     * del delta que no son de esta sucursal, no por el tamaño del catálogo: el
     * mismo orden de magnitud que la rama positiva. Un ítem ajeno que se edita
     * seguido se reporta como borrado más de una vez; es idempotente del lado
     * del cliente (borrar lo que no tiene es un no-op) y sale más barato que
     * llevar registro de qué vio cada device.
     *
     * El caso patológico es un ítem SIN ninguna fila en `item_outlet` (estado
     * inválido — ver mig 170 §4): sería ajeno a TODA sucursal y entraría acá en
     * cada delta. Si eso escalara, `MAX_REASONABLE_ROWS` corta y devuelve
     * `full = true`, que degrada a un bootstrap completo en vez de mandar una
     * lista de borrados gigante. El backfill de la migración existe justamente
     * para que ningún ítem preexistente nazca en ese estado.
     */
    private function itemsNoLongerVisibleTo(string $companyId, string $since, ?string $outletId): array
    {
        [$clause, $clauseParams] = outletInvisibilityClause($outletId);
        if ($clause === '') return [];

        $rs = $this->db->Execute(
            'SELECT i.itemId FROM item i
              WHERE i.companyId = ?
                AND COALESCE(i.updated_at, i.itemDate) > ?
                AND ' . $clause,
            array_merge([$companyId, $since], $clauseParams)
        );
        if ($rs === false) return [];
        $ids = [];
        foreach ($rs->GetRows() as $row) {
            $ids[] = $row['itemid'] ?? $row['itemId'] ?? null;
        }
        return array_values(array_filter($ids));
    }
}
