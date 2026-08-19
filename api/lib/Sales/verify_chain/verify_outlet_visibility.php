<?php

declare(strict_types=1);

/**
 * verify_outlet_visibility.php — arnés que demuestra, sin mockear nada, que
 * una caja (pos-app) SOLO ve el catálogo de SU sucursal (fix del bug "el POS
 * ofrece para vender artículos de otras sucursales", ver context/25-
 * sucursales-y-scopes.md §3 y el reporte del tester que originó este fix).
 *
 * `item.outletId` es una FK nullable 1:1 (`db-schema-postgres.sql`): NULL =
 * disponible en TODAS las sucursales, UUID = exclusivo de esa sucursal.
 * Antes de este fix, `/v1/items` (listado + bulk-get), el delta de
 * `/v1/sync?section=items` y la ficha de producto (`ItemService::getCore/
 * getInventory`) no aplicaban NINGÚN filtro por outlet — el bootstrap de una
 * caja bajaba el catálogo del tenant ENTERO, sin importar la sucursal.
 *
 * Fixtures propios (no toca seed.sql — crea su PROPIA sucursal B + 3 items
 * inline, mismo patrón que `verify_register_lease.php::verifyMakeDeviceReal()`):
 *   - ITEM_OUTLET_A → outletId = PY_OUTLET (la sucursal del seed base)
 *   - ITEM_OUTLET_B → outletId = una sucursal B nueva, propia de este arnés
 *   - ITEM_GLOBAL   → outletId = NULL (disponible en todas)
 *
 * Casos:
 *   1. `outletVisibilityClause()` + `buildItemsSelectSql()` (usado por el
 *      listado paginado y el bulk-get de `items.php`): filtrando por
 *      PY_OUTLET trae ITEM_OUTLET_A + ITEM_GLOBAL, NUNCA ITEM_OUTLET_B.
 *   2. Mismo filtro por la sucursal B: trae ITEM_OUTLET_B + ITEM_GLOBAL,
 *      NUNCA ITEM_OUTLET_A.
 *   3. Sin outletId (panel): trae los TRES — el panel no se restringe,
 *      administra el catálogo completo del tenant.
 *   4. `SyncService::itemsDelta()` con outletId: el delta de una caja
 *      tampoco reintroduce un ítem de otra sucursal aunque cambie después
 *      del bootstrap (si no filtrara acá también, un ítem excluido del
 *      bootstrap por outlet volvería a aparecer en el cache local del
 *      device en cuanto alguien lo edite en otra caja).
 *   5. `Services\ItemService::getCore()/getInventory()` (ficha de producto,
 *      `resource=core|inventory|info` de `items.php`): con un `TenantContext`
 *      de DEVICE (deviceId no vacío) apuntando a PY_OUTLET, pedir la ficha
 *      de ITEM_OUTLET_B devuelve null/[] (tratado como "no existe" — nunca
 *      confirma la existencia de un ítem ajeno). El mismo contexto SIN
 *      deviceId (panel) sí puede leer cualquiera de los tres.
 *
 * Requiere el mismo Postgres migrado+seedeado que run_sale_chain.php (seed.sql
 * ya cargado — usa PY_COMPANY/PY_OUTLET/PY_USER de ahí como base). No
 * depende de otros pasos del arnés — puede correr solo.
 *
 * Uso: ver run.sh (agregado como paso 3.13).
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Items\ItemRepository;
use Punto\Api\Items\ItemService as ItemsItemService;
use Punto\Api\Services\ItemService as PosItemService;
use Punto\Api\Sync\SyncService;

require_once dirname(__DIR__, 3) . '/lib/Items/ItemsQuery.php';
require_once dirname(__DIR__, 3) . '/lib/Sync/SyncService.php';
require_once dirname(__DIR__, 3) . '/lib/services/ItemService.php';

use function Punto\Api\Items\buildItemsSelectSql;
use function Punto\Api\Items\presentItem;
use function Punto\Api\Items\outletVisibilityClause;

$PY_COMPANY = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET  = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_USER    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

// Fixtures propios de este arnés — UUIDs fijos pero no enumerables, mismo
// criterio que seed.sql. Idempotente (ON CONFLICT en todo).
$OUTLET_B    = 'ab0e1e70-1111-4a1a-8b1b-000000000b01';
$ITEM_A      = 'ab0e1e70-1111-4a1a-8b1b-000000000a01'; // outletId = PY_OUTLET
$ITEM_B      = 'ab0e1e70-1111-4a1a-8b1b-000000000a02'; // outletId = OUTLET_B
$ITEM_GLOBAL = 'ab0e1e70-1111-4a1a-8b1b-000000000a03'; // outletId = NULL
$TAX_ID      = '3cf780bb-51d6-4b41-b52d-1e77bfb60969'; // "10% incluido" del seed PY

// Reconstruye lo que apiAuthTenant() hace en un request real, sin JWT —
// mismo patrón que verify_sync.php/verify_register_lease.php.
$companyId  = $PY_COMPANY;
$outletId   = $PY_OUTLET;
$userId     = $PY_USER;
$registerId = null;
$roleId     = '1';
require API_APP_DIR . '/data.php';

global $db;
$failures = [];

function verifyOutletCheck(string $label, bool $ok, string $detail, array &$failures): void
{
    if ($ok) {
        echo "[verify_outlet_visibility] OK   $label\n";
        return;
    }
    $failures[] = "$label — $detail";
    echo "[verify_outlet_visibility] FAIL $label\n     $detail\n";
}

// ── Setup: sucursal B propia + 3 items (A/B/global) ────────────────────────
$db->Execute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName, outletStatus = 1',
    [$OUTLET_B, 'Verify PY - Sucursal B (outlet visibility)', $PY_COMPANY]
);

function verifyUpsertItem(string $itemId, string $companyId, string $name, string $sku, float $price, string $taxId, ?string $outletId): void
{
    global $db;
    $db->Execute(
        "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, outletid, companyid)
              VALUES (?, ?, ?, ?, 'product', 1, TRUE, FALSE, ?, '{}'::jsonb, ?, ?)
         ON CONFLICT (itemid) DO UPDATE SET
              outletid = EXCLUDED.outletid, itemprice = EXCLUDED.itemprice, itemstatus = 1",
        [$itemId, $name, $sku, $price, $taxId, $outletId, $companyId]
    );
}

verifyUpsertItem($ITEM_A, $PY_COMPANY, 'Verify outlet A', 'VERIFY-OUTLET-A', 1000, $TAX_ID, $PY_OUTLET);
verifyUpsertItem($ITEM_B, $PY_COMPANY, 'Verify outlet B', 'VERIFY-OUTLET-B', 2000, $TAX_ID, $OUTLET_B);
verifyUpsertItem($ITEM_GLOBAL, $PY_COMPANY, 'Verify outlet global', 'VERIFY-OUTLET-GLOBAL', 3000, $TAX_ID, null);

/** Helper: corre `buildItemsSelectSql` con el filtro de outlet y devuelve los itemIds. */
function verifyFetchIds(array $ids, string $companyId, ?string $outletId): array
{
    global $db;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $whereSql     = "i.companyId = ? AND i.itemId IN ({$placeholders})";
    $params       = array_merge([$companyId], $ids);
    [$clause, $clauseParams] = outletVisibilityClause($outletId);
    if ($clause !== '') {
        $whereSql .= " AND {$clause}";
        $params    = array_merge($params, $clauseParams);
    }
    $sql = buildItemsSelectSql($whereSql);
    $rs  = $db->Execute($sql, $params);
    $out = [];
    if ($rs !== false) {
        foreach ($rs->GetRows() as $row) {
            $out[] = presentItem($row)['itemId'];
        }
    }
    sort($out);
    return $out;
}

$allThree = [$ITEM_A, $ITEM_B, $ITEM_GLOBAL];

// ── Caso 1: filtro por PY_OUTLET → A + global, nunca B ─────────────────────
$gotA = verifyFetchIds($allThree, $PY_COMPANY, $PY_OUTLET);
$expectA = [$ITEM_A, $ITEM_GLOBAL];
sort($expectA);
verifyOutletCheck(
    'caso 1: caja de PY_OUTLET ve item A + global, nunca item B',
    $gotA === $expectA,
    'esperaba ' . json_encode($expectA) . ', llegó ' . json_encode($gotA),
    $failures
);

// ── Caso 2: filtro por OUTLET_B → B + global, nunca A ───────────────────────
$gotB = verifyFetchIds($allThree, $PY_COMPANY, $OUTLET_B);
$expectB = [$ITEM_B, $ITEM_GLOBAL];
sort($expectB);
verifyOutletCheck(
    'caso 2: caja de OUTLET_B ve item B + global, nunca item A',
    $gotB === $expectB,
    'esperaba ' . json_encode($expectB) . ', llegó ' . json_encode($gotB),
    $failures
);

// ── Caso 3: sin outletId (panel) → los tres, sin restricción ───────────────
$gotPanel = verifyFetchIds($allThree, $PY_COMPANY, null);
$expectPanel = $allThree;
sort($expectPanel);
verifyOutletCheck(
    'caso 3: panel (sin outletId) ve el catálogo completo del tenant (los 3 items)',
    $gotPanel === $expectPanel,
    'esperaba ' . json_encode($expectPanel) . ', llegó ' . json_encode($gotPanel),
    $failures
);

// ── Caso 4: delta de sync (SyncService::itemsDelta) respeta el mismo filtro ─
// Watermark ANTES de bumpear los 3 items — mismo criterio que verify_sync.php.
$since = date('Y-m-d H:i:s', time() - 1);

$itemRepo        = new ItemRepository($db);
$itemsItemService = new ItemsItemService($itemRepo);
foreach ($allThree as $id) {
    $itemsItemService->update($id, $PY_COMPANY, ['itemDescription' => 'verify_outlet_visibility bump']);
}

$sync  = new SyncService($db);
$delta = $sync->itemsDelta($PY_COMPANY, $since, $PY_OUTLET);
$deltaIds = array_values(array_filter(
    array_map(static fn($row) => $row['itemId'] ?? null, $delta['items']),
    static fn($id) => in_array($id, $allThree, true)
));
sort($deltaIds);
verifyOutletCheck(
    'caso 4: delta de sync para una caja de PY_OUTLET NUNCA reintroduce el item de OUTLET_B',
    $deltaIds === $expectA,
    'esperaba ' . json_encode($expectA) . ' (A + global), llegó ' . json_encode($deltaIds),
    $failures
);

// ── Caso 5: ficha de producto (Services\ItemService) por device vs panel ───
$deviceCtx = new TenantContext(
    companyId:  $PY_COMPANY,
    outletId:   $PY_OUTLET,
    userId:     $PY_USER,
    registerId: '',
    roleId:     '1',
    deviceId:   'ab0e1e70-1111-4a1a-8b1b-00000000dev1', // solo necesita ser no-vacío
);
$panelCtx = new TenantContext(
    companyId:  $PY_COMPANY,
    outletId:   $PY_OUTLET,
    userId:     $PY_USER,
    registerId: '',
    roleId:     '1',
    deviceId:   '',
);

$posSvcDevice = new PosItemService($deviceCtx);
$posSvcPanel  = new PosItemService($panelCtx);

verifyOutletCheck(
    'caso 5a: getCore() desde una caja de PY_OUTLET SÍ ve el item de su propia sucursal',
    $posSvcDevice->getCore($ITEM_A, $PY_COMPANY) !== null,
    'getCore(ITEM_A) devolvió null para una caja de PY_OUTLET',
    $failures
);
verifyOutletCheck(
    'caso 5b: getCore() desde una caja de PY_OUTLET SÍ ve el item global (sin outlet)',
    $posSvcDevice->getCore($ITEM_GLOBAL, $PY_COMPANY) !== null,
    'getCore(ITEM_GLOBAL) devolvió null para una caja de PY_OUTLET',
    $failures
);
verifyOutletCheck(
    'caso 5c: getCore() desde una caja de PY_OUTLET NO ve el item de OUTLET_B (null, no 403 — trato de "no existe")',
    $posSvcDevice->getCore($ITEM_B, $PY_COMPANY) === null,
    'getCore(ITEM_B) devolvió datos a una caja de otra sucursal',
    $failures
);
verifyOutletCheck(
    'caso 5d: getInventory() desde una caja de PY_OUTLET NO ve el item de OUTLET_B',
    $posSvcDevice->getInventory($ITEM_B, $PY_COMPANY) === [],
    'getInventory(ITEM_B) devolvió datos a una caja de otra sucursal',
    $failures
);
verifyOutletCheck(
    'caso 5e: el panel (sin deviceId) SÍ puede leer la ficha de un item de CUALQUIER sucursal',
    $posSvcPanel->getCore($ITEM_B, $PY_COMPANY) !== null,
    'getCore(ITEM_B) devolvió null para el panel — no debería restringirse',
    $failures
);

if ($failures !== []) {
    fwrite(STDERR, "[verify_outlet_visibility] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_outlet_visibility] TODO OK\n";
exit(0);
