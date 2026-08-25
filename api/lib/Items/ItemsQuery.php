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
        'addongroups'         => 'addonGroups',
        'compounditems'       => 'compoundItems',
        // `item_outlet` (mig 170) — Postgres devuelve el alias en minúsculas.
        'outletsjson'         => 'outletsJson',
        // Categoría de GASTO del ítem (Finanzas) — NO es `categoryId` (esa es
        // la comercial, la que agrupa en el POS). Vive en `data.expenseCategoryId`
        // (JSONB, mismo patrón que itemUOM — ver `buildItemsSelectSql()`: no
        // hace falta tocar el SELECT, `_flattenJsonb`/`ncmRow` ya aplana TODA
        // la columna `data`, solo hace falta re-castear la key acá). Se usa
        // para precargar la categoría de cada línea al agregar el ítem a una
        // compra (owner 2026-08-20: "no obligatorio, pero fácil de heredar").
        'expensecategoryid'   => 'expenseCategoryId',
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
    // addonGroups (F4/F5 context/41, embebido F6 context/45): `json_agg(...)`
    // de Postgres llega como STRING (driver pdo_pgsql, mismo caso que `config`
    // en DocumentTemplateService::present()) — decodificar. `NULL` (item sin
    // grupos activos con opciones) → [], nunca null hacia el front: el POS
    // no debe distinguir "sin campo" de "sin add-ons".
    if (array_key_exists('addonGroups', $out)) {
        $raw = $out['addonGroups'];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $out['addonGroups'] = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($raw)) {
            $out['addonGroups'] = [];
        }
    } else {
        $out['addonGroups'] = [];
    }
    // compoundItems (receta del combo FIJO, `item_compound`): mismo trato que
    // addonGroups arriba — `json_agg(...)` llega como STRING, decodificar;
    // NULL (ítem sin receta, o sea el 99% del catálogo) → [], nunca null. El
    // POS lo necesita embebido y offline por el mismo motivo que addonGroups
    // (context/41): mostrar de qué se compone un combo fijo sin depender de
    // un fetch que falle sin red.
    if (array_key_exists('compoundItems', $out)) {
        $raw = $out['compoundItems'];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $out['compoundItems'] = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($raw)) {
            $out['compoundItems'] = [];
        }
    } else {
        $out['compoundItems'] = [];
    }
    // Sucursales del ítem (`item_outlet`, mig 170). Mismo trato que addonGroups
    // arriba: `json_agg(...)` llega como STRING con pdo_pgsql. Se exponen DOS
    // campos derivados de la misma lista:
    //   - `outlets`   → [{outletId, outletName}], para pintar sin otra query
    //   - `outletIds` → [id], que es lo que el form manda de vuelta
    // El invariante es "≥1 sucursal" (ver ItemOutletService), pero acá NO se
    // fuerza: si un ítem quedó en cero (tenant sin sucursales, o hard-delete de
    // la última sucursal — mig 170 §4), el front tiene que poder VERLO para
    // corregirlo, no recibir un dato inventado.
    $rawOutlets = $out['outletsJson'] ?? null;
    unset($out['outletsJson']);
    if (is_string($rawOutlets) && $rawOutlets !== '') {
        $decoded    = json_decode($rawOutlets, true);
        $rawOutlets = is_array($decoded) ? $decoded : [];
    } elseif (!is_array($rawOutlets)) {
        $rawOutlets = [];
    }
    $out['outlets']   = $rawOutlets;
    $out['outletIds'] = array_values(array_filter(array_map(
        static fn($o) => is_array($o) ? ($o['outletId'] ?? null) : null,
        $rawOutlets
    )));
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
                         WHERE ag.itemid = i.itemId
                           AND ag.companyid = i.companyId
                           AND ag.\"status\" = TRUE
                           AND EXISTS (
                                SELECT 1 FROM \"addon_group_option\" ago
                                 WHERE ago.groupid = ag.groupid
                           )
                   ) AS hasAddons,
                   -- Grupos de add-ons completos, embebidos en el ítem
                   -- (context/45-satelites-item-contact-sync.md — add-ons es
                   -- satélite de item; hasta que el trigger genérico de ese
                   -- plan exista, este SELECT compartido — bootstrap,
                   -- bulk-get, delta — es el único lugar que arma el shape,
                   -- así que los tres caminos no pueden divergir). NULL
                   -- (sin grupos) es intencional acá: `presentItem()` lo
                   -- normaliza a `[]` para el front. `LEFT JOIN LATERAL`
                   -- correlacionado por itemId (índice ix_addon_group_item)
                   -- — barato para el >90% de ítems sin add-ons.
                   addons.groups AS addonGroups,
                   -- Receta del combo FIJO (`item_compound`, mig 19),
                   -- embebida igual que addonGroups arriba: el POS la
                   -- necesita offline para mostrar de qué se compone un
                   -- combo fijo al seleccionarlo (context/41-addons-y-combos,
                   -- hallazgo 2026-08-19 — hoy NO viajaba, el combo fijo se
                   -- agregaba al carrito sin ninguna vista previa). Tabla
                   -- vieja (2026-06-23, previa a la convención de quoted
                   -- camelCase): columnas SIN comillas a propósito, quotearlas
                   -- rompería en runtime (no matchean las lowercase reales).
                   compound.items AS compoundItems,
                   -- Sucursales del ítem (`item_outlet`, mig 170). Viaja acá y
                   -- no en un fetch aparte por la misma razón que addonGroups:
                   -- este SELECT es la ÚNICA fuente de listado, bulk-get y
                   -- delta de sync, así que los tres shapes no pueden divergir.
                   -- Se embeben id Y nombre: la ficha del panel pinta los
                   -- nombres sin una segunda query, y el POS ya trae el dato si
                   -- alguna vez lo necesita.
                   outlets.list AS outletsJson
              FROM item i
         LEFT JOIN LATERAL (
              SELECT json_agg(
                       json_build_object(
                         'outletId', io.outletid,
                         'outletName', oo.outletName
                       ) ORDER BY oo.outletName ASC
                     ) AS list
                FROM item_outlet io
                JOIN outlet oo ON oo.outletId = io.outletid
               WHERE io.itemid = i.itemId
                 AND io.companyid = i.companyId
         ) outlets ON true
         LEFT JOIN LATERAL (
              SELECT json_agg(
                       json_build_object(
                         'id', ag.groupid,
                         'name', ag.\"name\",
                         'minSelect', ag.minselect,
                         'maxSelect', ag.maxselect,
                         'sort', ag.\"sort\",
                         'options', COALESCE((
                              SELECT json_agg(
                                       json_build_object(
                                         'id', ago.optionid,
                                         'itemId', ago.itemid,
                                         'itemName', oi.itemName,
                                         'priceDelta', ago.pricedelta,
                                         'isDefault', ago.isdefault,
                                         'isLocked', ago.islocked,
                                         'maxQty', ago.maxqty,
                                         'sort', ago.\"sort\"
                                       ) ORDER BY ago.\"sort\" ASC, ago.optionid ASC
                                     )
                                FROM \"addon_group_option\" ago
                                JOIN item oi ON oi.itemId = ago.itemid
                               WHERE ago.groupid = ag.groupid
                         ), '[]'::json)
                       ) ORDER BY ag.\"sort\" ASC, ag.groupid ASC
                     ) AS groups
                FROM \"addon_group\" ag
               WHERE ag.itemid = i.itemId
                 AND ag.companyid = i.companyId
                 AND ag.\"status\" = TRUE
         ) addons ON true
         LEFT JOIN LATERAL (
              SELECT json_agg(
                       json_build_object(
                         'itemId', ic.childItemId,
                         'itemName', ci.itemName,
                         'quantity', ic.quantity,
                         -- `itemUOM` NO es columna de `item`: vive en el JSONB
                         -- `data`. Referenciarla como columna hacia fallar el SELECT
                         -- ENTERO del catalogo (incidente 2026-08-19: el POS no
                         -- cargaba ningun producto).
                         'uom', ci.data->>'itemUOM',
                         'sort', ic.sort
                       ) ORDER BY ic.sort ASC, ic.compoundId ASC
                     ) AS items
                FROM item_compound ic
                JOIN item ci ON ci.itemId = ic.childItemId AND ci.companyId = i.companyId
               WHERE ic.parentItemId = i.itemId
                 AND ic.companyId = i.companyId
         ) compound ON true
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

/**
 * Fragmento de visibilidad por sucursal para `pos-app` (una caja).
 *
 * La pertenencia de un ítem a sucursales vive en `item_outlet` (N-a-N, mig
 * 170): un ítem está en 1..N sucursales y NUNCA en cero — "cero filas" es un
 * estado inválido, no un comodín de "todas" (OJO: `contact_outlet`, mig 66,
 * sí usa cero = todas; NO trasladar esa lectura acá). El invariante lo
 * sostiene el write-path de `ItemService` (422 ante lista vacía); ver la §4
 * de la mig 170 para por qué no es un constraint de base.
 *
 * Una caja solo debe poder vender/consultar lo que le corresponde — antes de
 * esto, `/v1/items`, el bulk-get y el delta de sync no aplicaban ningún
 * filtro de outlet y el POS ofrecía para vender ítems de otras sucursales
 * (context/29 lo documenta como riesgo fiscal de otro tipo; esto es el
 * mismo principio aplicado al catálogo).
 *
 * `$outletId` viene SIEMPRE de la fila `device` resuelta en
 * `apiAuthTenant()` (`$ctx['outletId']` cuando `$ctx['realm'] === 'pos-app'`)
 * — nunca de query/body, mismo criterio que `outletScope` en
 * `spaces.php`/`orders-core.php` (ver `context/25-sucursales-y-scopes.md`
 * §2). El panel NO pasa `$outletId` acá: administra el catálogo completo
 * del tenant (Scope=Tenant, ver §3 del mismo doc), así que el listado/ficha
 * consolidados no deben restringirse.
 *
 * @return array{0: string, 1: list<string>} [fragmento SQL (o '' si no aplica), params]
 */
function outletVisibilityClause(?string $outletId): array
{
    if ($outletId === null || $outletId === '') {
        return ['', []];
    }
    return [
        'EXISTS (SELECT 1 FROM item_outlet io WHERE io.itemid = i.itemId AND io.companyid = i.companyId AND io.outletid = ?)',
        [$outletId],
    ];
}

/**
 * La NEGACIÓN de `outletVisibilityClause()` — ítems que NO pertenecen a esta
 * sucursal.
 *
 * Existe para un solo consumidor: `SyncService::itemsDelta()`. Cuando a un
 * ítem le sacan la sucursal de una caja, el ítem no se borra — deja de
 * existir PARA ESA CAJA. Sin este fragmento, el delta simplemente no lo trae
 * (el filtro positivo lo descarta) y el device se queda con la copia vieja en
 * cache para siempre, vendible. Con él, el delta lo puede reportar como
 * removido, que es lo que la caja necesita saber.
 *
 * Se define acá, pegado a su contraparte, para que las dos no puedan
 * divergir: cualquier cambio al criterio de pertenencia se hace en este par
 * de funciones y en ningún otro lado.
 *
 * @return array{0: string, 1: list<string>} [fragmento SQL (o '' si no aplica), params]
 */
function outletInvisibilityClause(?string $outletId): array
{
    if ($outletId === null || $outletId === '') {
        return ['', []];
    }
    return [
        'NOT EXISTS (SELECT 1 FROM item_outlet io WHERE io.itemid = i.itemId AND io.companyid = i.companyId AND io.outletid = ?)',
        [$outletId],
    ];
}
