<?php
declare(strict_types=1);
namespace Punto\Api\Services;
use Punto\Api\Context\TenantContext;
// DB not needed (uses ncmExecute helpers)

/**
 * ItemService — operaciones de lectura sobre ítems del catálogo (Slice 25).
 *
 * Lógica portada de app/load.php:
 *   getInfo (L2612) — detalles de un ítem + stock por outlet + depósitos.
 *
 * Bugs PG corregidos respecto al legacy:
 *   - taxonomyType = "location" (comillas dobles → identificador, no literal)
 *     → corregido a 'location' (comilla simple).
 *
 * Los helpers (getTaxonomyName, getItemTypeName, getItemStock, getCurrentOutletName,
 * formatCurrentNumber, formatQty, iftn, enc) están disponibles vía api/bootstrap.php
 * que incluye app/includes/functions.php a través de head.php.
 */

final class ItemService
{
    public function __construct(
        public readonly TenantContext $ctx,
    ) {}

    // ========================================================================
    // RECURSOS GRANULARES (patrón BFF-compone — ver §22.12)
    //
    // getInfo() abajo es el composite legacy (la API arma toda la pantalla). Lo
    // descompongo en dos piezas reusables e independientes:
    //   getCore()      — campos del ítem + nombres de FK (category/brand/tax/type).
    //   getInventory() — stock por outlet/depósito (sólo si itemTrackInventory).
    // El BFF (app/bff/items.php) las pide EN PARALELO y mergea (ENSAMBLAJE PURO:
    // sin cómputo de rollup en el BFF, a diferencia de drawer §22.12.1). Un caller
    // que sólo necesita el precio/SKU usa getCore y evita el loop de inventario.
    // ========================================================================

    /**
     * Campos núcleo de un ítem (sin inventario). Filtra por companyId (tenant).
     *
     * @return array|null null si el ítem no existe o no pertenece al tenant.
     */
    public function getCore(string $itemId, string $companyId): ?array
    {
        global $dec, $ts;

        $item = ncmExecute(
            'SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$item || $this->isForeignOutletItem($item)) {
            return null;
        }

        $eItemId    = enc($item['itemId']);
        $eCompanyId = enc($companyId);

        // Imagen primaria desde la galeria moderna (item_image + DO Spaces). URL S3 cruda;
        // null si el item no tiene imagen (el front muestra placeholder).
        $imgRow = ncmExecute(
            'SELECT url FROM item_image WHERE itemId = ? AND companyId = ? ORDER BY sort ASC, created_at ASC LIMIT 1',
            [$item['itemId'], $companyId]
        );
        $imgUrl = (is_array($imgRow) && !empty($imgRow['url'])) ? (string) $imgRow['url'] : null;

        return [
            'id'          => $eItemId,
            'name'        => $item['itemName'],
            'img'         => $imgUrl,
            'price'       => CURRENCY . ' ' . formatCurrentNumber($item['itemPrice'], $dec, $ts),
            'sku'         => iftn($item['itemSKU'], 'Sin SKU'),
            'type'        => getItemTypeName($item),
            'description' => $item['itemDescription'],
            'tax'         => iftn(getTaxonomyName($item['taxId'], true), '0'),
            'category'    => iftn(getTaxonomyName($item['categoryId'], true), 'Sin categoría'),
            // Sucursales del ítem (`item_outlet`, mig 170). Ya no existe el
            // "Todas" del modelo viejo (outletId NULL): las sucursales son
            // explícitas, así que se listan por nombre.
            'outlet'      => $this->outletNamesLabel($item['itemId'], $companyId),
            'brand'       => iftn(getTaxonomyName($item['brandId'], true), 'Sin marca'),
            'duration'    => iftn($item['itemDuration'], ''),
            'sessions'    => iftn($item['itemSessions'], ''),
        ];
    }

    /**
     * Inventario de un ítem: stock por sucursal y depósito. Sólo computa si el
     * ítem trackea inventario; devuelve `[]` si no, si no existe, o si no es
     * del tenant.
     *
     * F1 de context/52 — reimplementado sobre el LEDGER (D1). Antes mezclaba
     * el snapshot `stockOnHand` (total de la sucursal) con la tabla espejo
     * `toLocation` (los depósitos) y derivaba el principal restando, con un
     * `$dTotal` ACUMULATIVO que restaba de más: con 3 depósitos de 10 unidades
     * cada uno el principal salía 60 unidades por debajo del real (restaba 10,
     * después 20, después 30). Encima `toLocation` ya no se escribe.
     *
     * Ahora el total y el desglose salen de las MISMAS filas
     * (`SUM(stockCount)` agrupado por `locationId`, vía
     * `Inventory::onHandByLocation()`), así que "Principal" es el grupo sin
     * depósito asignado y la suma de los depósitos más el principal da el
     * total por construcción — no hay resta que pueda desalinearse.
     *
     * @return array `[]` | `['outlets' => [...]]` — exactamente lo que va en la clave `inventory`.
     */
    public function getInventory(string $itemId, string $companyId): array
    {
        $item = ncmExecute(
            'SELECT itemId, itemTrackInventory, outletId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$item || !$item['itemTrackInventory'] || $this->isForeignOutletItem($item)) {
            return [];
        }

        $inventory = [];
        $outlet = ncmExecute(
            'SELECT * FROM outlet WHERE outletStatus = 1 AND companyId = ? LIMIT 100',
            [$companyId],
            false,
            true
        );

        if ($outlet) {
            while (!$outlet->EOF) {
                $outletId = (string) $outlet->fields['outletId'];

                // Una sola pasada al ledger por sucursal: saldo por depósito,
                // clave '' = principal (locationId IS NULL).
                $byLocation = \Punto\App\Domain\Inventory::onHandByLocation($item['itemId'], $outletId);
                $total      = array_sum($byLocation);

                $depo = ncmExecute(
                    "SELECT * FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC",
                    [$outletId],
                    false,
                    true
                );

                $deposits  = [];
                $listedSum = 0.0;
                if ($depo) {
                    while (!$depo->EOF) {
                        $locId      = (string) $depo->fields['taxonomyId'];
                        $qty        = (float) ($byLocation[$locId] ?? 0);
                        $listedSum += $qty;
                        $deposits[] = [
                            'depositName' => $depo->fields['taxonomyName'],
                            'qty'         => formatQty($qty),
                        ];
                        $depo->MoveNext();
                    }
                    $depo->Close();
                }

                // "Principal" absorbe todo lo que no cayó en un depósito
                // listado: el grupo `locationId IS NULL` MÁS cualquier grupo
                // cuyo depósito ya no exista. Así la suma de las filas siempre
                // da el total y no hay stock que se evapore de la vista.
                $deposits[] = [
                    'depositName' => 'Principal',
                    'qty'         => formatQty($total - $listedSum),
                ];

                $inventory['outlets'][] = [
                    'outletName' => $outlet->fields['outletName'],
                    'deposits'   => $deposits,
                    'total'      => formatQty($total),
                ];

                $outlet->MoveNext();
            }
            $outlet->Close();
        }

        return $inventory;
    }

    /**
     * Retorna los detalles de un ítem (core + inventario por outlet).
     * Composite legacy/backward-compat — el path vigente es la composición en el
     * BFF (app/bff/items.php) a partir de getCore() + getInventory() en paralelo.
     *
     * @return array|null null si el ítem no existe o no pertenece al tenant.
     */
    public function getInfo(string $itemId, string $companyId): ?array
    {
        $core = $this->getCore($itemId, $companyId);
        if ($core === null) {
            return null;
        }
        $core['inventory'] = $this->getInventory($itemId, $companyId);
        return $core;
    }

    /**
     * `true` si el request viene de una CAJA (pos-app, `ctx->deviceId` no
     * vacío — ver `TenantContext`) y el ítem NO pertenece a la sucursal de esa
     * caja.
     *
     * La pertenencia vive en `item_outlet` (N-a-N, mig 170) — mismo criterio
     * que `outletVisibilityClause()` (`api/lib/Items/ItemsQuery.php`) para el
     * listado/bulk-get/delta, expresado acá como una consulta directa porque
     * el caller trae una fila cruda de `SELECT * FROM item` (sin el
     * `outletIds` que arma `presentItem()`). Es un lookup por PK
     * `(itemid, outletid)`.
     *
     * La caja trata un ítem de otra sucursal como si no existiera (404), nunca
     * como un 403 que confirme su existencia. El panel (`ctx->deviceId === ''`)
     * nunca está restringido acá — administra el catálogo del tenant.
     *
     * OJO con el cambio de modelo: antes un ítem con `outletId` NULL era
     * "visible en todas" y NUNCA era foráneo. Hoy la pertenencia es explícita,
     * así que un ítem sin ninguna fila en `item_outlet` es foráneo para
     * CUALQUIER caja. La mig 170 backfillea una fila por sucursal justamente
     * para que ningún ítem preexistente caiga en ese estado.
     */
    /**
     * Nombres de las sucursales del ítem, para mostrar en la ficha.
     * `'Sin sucursal'` solo puede pasar en el estado inválido que documenta la
     * §4 de la mig 170 (hard-delete de la última sucursal) — se muestra tal
     * cual, a propósito: es un dato que hay que corregir, no que disimular.
     */
    private function outletNamesLabel(string $itemId, string $companyId): string
    {
        // `ncmRows()` y no `getAssoc`: acá se quieren TODAS las filas, y
        // getAssoc indexa por la primera columna proyectada — dos sucursales
        // con el mismo nombre colapsarían en una sin aviso.
        $rows = ncmRows(
            'SELECT o.outletName AS name
               FROM item_outlet io
               JOIN outlet o ON o.outletId = io.outletid
              WHERE io.itemid = ? AND io.companyid = ?
              ORDER BY o.outletName ASC',
            [$itemId, $companyId]
        );
        $names = [];
        foreach ($rows as $r) {
            $n = $r['name'] ?? null;
            if ($n) $names[] = (string) $n;
        }
        return $names === [] ? 'Sin sucursal' : implode(', ', $names);
    }

    private function isForeignOutletItem(array|\CaseInsensitiveArray $item): bool
    {
        if ($this->ctx->deviceId === '') {
            return false;
        }
        $itemId = (string) ($item['itemId'] ?? '');
        if ($itemId === '') {
            return true;
        }
        // `companyid` no hace falta para que sea seguro (el `outletid` ya viene
        // del device, o sea tenant-scoped), pero va igual: es el único call-site
        // de `item_outlet` que quedaría sin él, y un lector futuro no debería
        // tener que reconstruir por qué este es la excepción.
        $hit = ncmExecute(
            'SELECT 1 AS ok FROM item_outlet
              WHERE itemid = ? AND outletid = ? AND companyid = ? LIMIT 1',
            [$itemId, $this->ctx->outletId, $this->ctx->companyId]
        );
        return empty($hit);
    }
}
