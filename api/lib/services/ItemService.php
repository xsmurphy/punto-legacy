<?php
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

class ItemService
{
    /**
     * Retorna los detalles de un ítem (info + inventario por outlet).
     * Port fiel de load.php L2612–L2716; filtra por companyId (tenant).
     *
     * @return array|null null si el ítem no existe o no pertenece al tenant.
     */
    public function getInfo(string $itemId, string $companyId): ?array
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

        $categoryName = iftn(getTaxonomyName($item['categoryId'], true), 'Sin categoría');
        $brandName    = iftn(getTaxonomyName($item['brandId'], true), 'Sin marca');
        $taxName      = iftn(getTaxonomyName($item['taxId'], true), '0');
        $typeName     = getItemTypeName($item);
        $inventory    = [];

        if ($item['itemTrackInventory']) {
            $outlet = ncmExecute(
                'SELECT * FROM outlet WHERE outletStatus = 1 AND companyId = ? LIMIT 100',
                [$companyId],
                false,
                true
            );

            if ($outlet) {
                while (!$outlet->EOF) {
                    $deposits = [];
                    $oCount   = 0;
                    $mCount   = 0;

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
        }

        return [
            'id'          => $eItemId,
            'name'        => $item['itemName'],
            'img'         => '/assets/250-250/0/' . $eCompanyId . '_' . $eItemId . '.jpg?' . mt_rand(),
            'price'       => CURRENCY . ' ' . formatCurrentNumber($item['itemPrice'], $dec, $ts),
            'sku'         => iftn($item['itemSKU'], 'Sin SKU'),
            'type'        => $typeName,
            'description' => $item['itemDescription'],
            'tax'         => $taxName,
            'category'    => $categoryName,
            'outlet'      => iftn($item['outletId'], 'Todas', getCurrentOutletName($item['outletId'])),
            'brand'       => $brandName,
            'duration'    => iftn($item['itemDuration'], ''),
            'sessions'    => iftn($item['itemSessions'], ''),
            'inventory'   => $inventory,
        ];
    }
}
