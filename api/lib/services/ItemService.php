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
        if (!$item) {
            return null;
        }

        $eItemId    = enc($item['itemId']);
        $eCompanyId = enc($companyId);

        return [
            'id'          => $eItemId,
            'name'        => $item['itemName'],
            'img'         => '/assets/250-250/0/' . $eCompanyId . '_' . $eItemId . '.jpg?' . mt_rand(),
            'price'       => CURRENCY . ' ' . formatCurrentNumber($item['itemPrice'], $dec, $ts),
            'sku'         => iftn($item['itemSKU'], 'Sin SKU'),
            'type'        => getItemTypeName($item),
            'description' => $item['itemDescription'],
            'tax'         => iftn(getTaxonomyName($item['taxId'], true), '0'),
            'category'    => iftn(getTaxonomyName($item['categoryId'], true), 'Sin categoría'),
            'outlet'      => iftn($item['outletId'], 'Todas', getCurrentOutletName($item['outletId'])),
            'brand'       => iftn(getTaxonomyName($item['brandId'], true), 'Sin marca'),
            'duration'    => iftn($item['itemDuration'], ''),
            'sessions'    => iftn($item['itemSessions'], ''),
        ];
    }

    /**
     * Inventario de un ítem: stock por outlet y depósito. Sólo computa si el ítem
     * trackea inventario; devuelve `[]` si no, si no existe, o si no es del tenant.
     * Self-contained: re-lee el ítem (PK lookup barato) para ser un recurso suelto.
     *
     * @return array `[]` | `['outlets' => [...]]` — exactamente lo que va en la clave `inventory`.
     */
    public function getInventory(string $itemId, string $companyId): array
    {
        $item = ncmExecute(
            'SELECT itemId, itemTrackInventory FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$item || !$item['itemTrackInventory']) {
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
                $deposits = [];

                $oStock = getItemStock($item['itemId'], $outlet->fields['outletId']);
                $oCount = $oStock['stockOnHand'] ?? 0;
                $mCount = $oCount;

                // Bug PG corregido: comillas dobles → 'location' (literal de string)
                $depo = ncmExecute(
                    "SELECT * FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC",
                    [$outlet->fields['outletId']],
                    false,
                    true
                );

                if ($depo) {
                    $dTotal = 0;
                    while (!$depo->EOF) {
                        $dCount   = 0;
                        $depCount = ncmExecute(
                            'SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',
                            [$depo->fields['taxonomyId'], $item['itemId']]
                        );
                        if ($depCount) {
                            $dCount = $depCount['toLocationCount'];
                        }
                        $dTotal += $dCount;
                        $deposits[] = [
                            'depositName' => $depo->fields['taxonomyName'],
                            'qty'         => formatQty($dCount),
                        ];
                        $mCount = $mCount - $dTotal;
                        $depo->MoveNext();
                    }
                }

                $deposits[] = [
                    'depositName' => 'Principal',
                    'qty'         => formatQty($mCount),
                ];

                $inventory['outlets'][] = [
                    'outletName' => $outlet->fields['outletName'],
                    'deposits'   => $deposits,
                    'total'      => formatQty($oCount),
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
}
