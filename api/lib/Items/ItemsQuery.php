<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * Mapea una fila de `item` (lowercase desde PG + JSONB flatten) a camelCase
 * canonical para el front. Expone `kind` (desde `itemKind`), `categories[]`,
 * y `tags[]` (desde JSONB data.tags).
 *
 * Extraída de `api/v1/items.php` (2026-08-16, context/43-sync-incremental.md)
 * por el mismo motivo que `buildItemsSelectSql()` abajo: el delta de
 * `/v1/sync?section=items` necesita el MISMO shape de fila que el listado y
 * el bulk-get, sin duplicar el mapeo a mano.
 */
function presentItem(array|\CaseInsensitiveArray $row): array
{
    $map = [
        'itemid'              => 'itemId',
        'itemname'            => 'itemName',
        'itemsku'             => 'itemSKU',
        'itemcost'            => 'itemCost',
        'itemprice'           => 'itemPrice',
        'itemisparent'        => 'itemIsParent',
        'itemparentid'        => 'itemParentId',
        'itemtype'            => 'itemType',
        'itemkind'            => 'kind',
        'itemimage'           => 'itemImage',
        'itemstatus'          => 'itemStatus',
        'itemtrackinventory'  => 'itemTrackInventory',
        'itemcansale'         => 'itemCanSale',
        'itemtaxexcluded'     => 'itemTaxExcluded',
        'itemdiscount'        => 'itemDiscount',
        'itemuom'             => 'itemUOM',
        'itemsort'            => 'itemSort',
        'itemproduction'      => 'itemProduction',
        'itemtaxincluded'     => 'itemTaxIncluded',
        'itemdescription'     => 'itemDescription',
        'itemdate'            => 'itemDate',
        'taxid'               => 'taxId',
        'brandid'             => 'brandId',
        'categoryid'          => 'categoryId',
        'supplierid'          => 'supplierId',
        'locationid'          => 'locationId',
        'outletid'            => 'outletId',
        'companyid'           => 'companyId',
        'categoryname'        => 'categoryName',
        'brandname'           => 'brandName',
        'outletname'          => 'outletName',
        'coverimageurl'       => 'coverImageUrl',
        'childcount'          => 'childCount',
        'variantparentid'     => 'variantParentId',
        'hasvariants'         => 'hasVariants',
        'variantattributes'   => 'variantAttributes',
        'variantcount'        => 'variantCount',
        'itemminstock'        => 'itemMinStock',
        'itemmaxstock'        => 'itemMaxStock',
        'stockonhand'         => 'stockOnHand',
        'hasaddons'           => 'hasAddons',
    ];
    $out = [];
    foreach ($row as $k => $v) {
        $kLower = strtolower((string) $k);
        $key = $map[$kLower] ?? $k;
        $out[$key] = $v;
    }
    // Normalizar booleanos: PG con PDO puede devolver 't'/'f' string en vez de
    // bool dependiendo del driver/version. Forzamos bool real para que el
    // frontend no tenga que adivinar.
    foreach (['itemIsParent', 'itemTrackInventory', 'itemCanSale', 'itemProduction', 'itemTaxIncluded', 'itemEcom', 'itemFeatured', 'itemImage', 'hasVariants', 'hasAddons'] as $boolKey) {
        if (array_key_exists($boolKey, $out)) {
            $v = $out[$boolKey];
            if (is_string($v)) {
                $out[$boolKey] = ($v === 't' || $v === 'true' || $v === '1');
            } elseif (is_int($v)) {
                $out[$boolKey] = $v > 0;
            } elseif (is_bool($v)) {
                // ya está bien
            } elseif ($v === null) {
                $out[$boolKey] = false;
            }
        }
    }
    // childCount como int (PG devuelve string para COUNT en algunos drivers).
    if (isset($out['childCount'])) {
        $out['childCount'] = (int) $out['childCount'];
    }
    // Saldo y mínimo como float. `itemMinStock` conserva el null: NULL es "sin
    // mínimo definido", que no es lo mismo que 0 ("avisame al llegar a cero").
    if (array_key_exists('stockOnHand', $out)) {
        $out['stockOnHand'] = (float) $out['stockOnHand'];
    }
    foreach (['itemMinStock', 'itemMaxStock'] as $umbral) {
        if (array_key_exists($umbral, $out)) {
            $out[$umbral] = $out[$umbral] === null ? null : (float) $out[$umbral];
        }
    }
    // tags desde JSONB (data.tags es array o null)
    if (!isset($out['tags'])) {
        $rawTags = $out['tags'] ?? null;
        $out['tags'] = is_array($rawTags) ? $rawTags : [];
    }
    return $out;
}

/**
 * SELECT compartido de `item` — extraído de `api/v1/items.php` (2026-08-16,
 * context/43-sync-incremental.md) para que un TERCER caller (el delta de
 * `/v1/sync?section=items`) no tenga que copiar el SQL a mano. Antes de esta
 * extracción la función vivía como free function dentro de items.php y solo
 * el listado paginado + el bulk-get quirúrgico (context/15) la compartían;
 * el sync incremental es el mismo caso de "no diverger el shape de fila"
 * que motivó la extracción original, ahora cruzando archivo.
 *
 * Mismo SELECT, mismo comportamiento — ver `api/v1/items.php` (que ahora
 * importa esta función) para el detalle de cada columna/JOIN.
 *
 * @param string $whereSql WHERE ya armado, con alias `i.` donde corresponda.
 * @param string $tailSql  Cola opcional (ORDER BY/LIMIT/OFFSET).
 */
function buildItemsSelectSql(string $whereSql, string $tailSql = ''): string
{
    return "SELECT i.itemId, i.itemName, i.itemSKU, i.itemType, i.itemKind, i.itemStatus,
                   i.itemPrice, i.itemCost, i.itemDate, i.updated_at,
                   i.itemCanSale, i.itemTrackInventory, i.taxId,
                   i.itemIsParent, i.itemParentId,
                   i.variantParentId, i.hasVariants, i.variantAttributes,
                   i.categoryId, i.brandId, i.outletId, i.data,
                   i.itemMinStock, i.itemMaxStock,
                   COALESCE(st.onhand, 0) AS stockOnHand,
                   cat.taxonomyName AS categoryName,
                   brand.taxonomyName AS brandName,
                   o.outletName AS outletName,
                   cov.url AS coverImageUrl,
                   COALESCE(ch.cnt, 0) AS childCount,
                   COALESCE(vc.vcnt, 0) AS variantCount,
                   -- F4 (context/41): ¿el ítem tiene grupos de add-ons
                   -- vigentes? El POS lo necesita POR ÍTEM para decidir si
                   -- el tap abre el modal de selección o agrega directo —
                   -- sin este flag serían N fetch (uno por tile). EXISTS
                   -- correlacionado, no un JOIN que multiplique filas.
                   -- Un grupo activo SIN opciones no cuenta: abriría un
                   -- modal vacío.
                   EXISTS (
                        SELECT 1 FROM \"addon_group\" ag
                         WHERE ag.\"itemId\" = i.itemId
                           AND ag.\"companyId\" = i.companyId
                           AND ag.\"status\" = TRUE
                           AND EXISTS (
                                SELECT 1 FROM \"addon_group_option\" ago
                                 WHERE ago.\"groupId\" = ag.\"groupId\"
                           )
                   ) AS hasAddons
              FROM item i
         LEFT JOIN taxonomy cat   ON cat.taxonomyId   = i.categoryId
         LEFT JOIN taxonomy brand ON brand.taxonomyId = i.brandId
         LEFT JOIN outlet o       ON o.outletId       = i.outletId
         LEFT JOIN LATERAL (
              SELECT url FROM item_image
               WHERE itemId = i.itemId
               ORDER BY sort ASC, created_at ASC LIMIT 1
         ) cov ON true
         LEFT JOIN LATERAL (
              SELECT COUNT(*) AS cnt FROM item c
               WHERE c.itemParentId = i.itemId AND c.itemStatus = 1
         ) ch ON true
         LEFT JOIN LATERAL (
              SELECT COUNT(*) AS vcnt FROM item v
               WHERE v.variantParentId = i.itemId AND v.itemStatus = 1
         ) vc ON true
         -- Saldo real: SUMA de los movimientos, no el `stockOnHand` de la
         -- última fila. El snapshot de la última fila se desincroniza con
         -- cualquier movimiento cargado con fecha anterior (una compra
         -- fechada ayer) — mismo criterio que `Inventory::onHand()`.
         LEFT JOIN LATERAL (
              SELECT SUM(s.stockCount) AS onhand FROM stock s
               WHERE s.itemId = i.itemId AND s.companyId = i.companyId
         ) st ON true
             WHERE {$whereSql}
             {$tailSql}";
}
