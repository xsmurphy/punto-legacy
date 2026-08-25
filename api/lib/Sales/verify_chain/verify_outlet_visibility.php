<?php

declare(strict_types=1);

/**
 * verify_outlet_visibility.php — arnés que demuestra, sin mockear nada, que
 * una caja (pos-app) SOLO ve el catálogo de SU sucursal (fix del bug "el POS
 * ofrece para vender artículos de otras sucursales", ver context/25-
 * sucursales-y-scopes.md §3 y el reporte del tester que originó este fix).
 *
 * La pertenencia de un ítem a sucursales vive en `item_outlet` (N-a-N, mig
 * 170): un ítem está en 1..N sucursales, y CERO es un estado inválido. Antes
 * de la mig 170 era `item.outletId`, una FK nullable 1:1 donde NULL
 * significaba "disponible en TODAS las sucursales".
 *
 * Fixtures propios (no toca seed.sql — crea su PROPIA sucursal B + 3 items
 * inline, mismo patrón que `verify_register_lease.php::verifyMakeDeviceReal()`):
 *   - ITEM_OUTLET_A → solo PY_OUTLET (la sucursal del seed base)
 *   - ITEM_OUTLET_B → solo la sucursal B, propia de este arnés
 *   - ITEM_AMBAS    → las DOS (el equivalente del viejo outletId = NULL)
 *
 * Casos:
 *   1. `outletVisibilityClause()` + `buildItemsSelectSql()` (usado por el
 *      listado paginado y el bulk-get de `items.php`): filtrando por
 *      PY_OUTLET trae ITEM_OUTLET_A + ITEM_AMBAS, NUNCA ITEM_OUTLET_B.
 *   2. Mismo filtro por la sucursal B: trae ITEM_OUTLET_B + ITEM_AMBAS,
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
 *   6. **El caso que la mig 170 hace posible y es el más fácil de romper:**
 *      a un ítem le SACAN la sucursal de la caja. El ítem no se borró, pero
 *      para esa caja dejó de existir. El delta tiene que reportarlo en
 *      `deletedIds` — si solo lo omitiera de `items` (que es lo que hace el
 *      filtro positivo por sí solo), el device se quedaría con la copia vieja
 *      en cache, vendible, para siempre. Se verifica además que la OTRA caja
 *      (la que conserva el ítem) NO lo reciba como borrado.
 *   7. El invariante de mínimo-una-sucursal: `ItemOutletService::replace()`
 *      con lista vacía tira InvalidArgumentException (el 422 del endpoint).
 *   8. Aislamiento multi-tenant: una sucursal de OTRA empresa en la lista
 *      tira InvalidArgumentException, nunca inserta.
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
use Punto\Api\Items\ItemOutletService;
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
$ITEM_A      = 'ab0e1e70-1111-4a1a-8b1b-000000000a01'; // solo PY_OUTLET
$ITEM_B      = 'ab0e1e70-1111-4a1a-8b1b-000000000a02'; // solo OUTLET_B
$ITEM_GLOBAL = 'ab0e1e70-1111-4a1a-8b1b-000000000a03'; // AMBAS sucursales
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

/**
 * Crea el ítem y le asigna sus sucursales vía `ItemOutletService` — a
 * propósito el MISMO camino que usa el endpoint, no un INSERT directo a
 * `item_outlet`: así el arnés también ejercita el write-path real.
 *
 * @param string[] $outletIds
 */
function verifyUpsertItem(string $itemId, string $companyId, string $name, string $sku, float $price, string $taxId, array $outletIds): void
{
    global $db;
    $db->Execute(
        "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus, itemcansale, itemtrackinventory, taxid, data, companyid)
              VALUES (?, ?, ?, ?, 'product', 1, TRUE, FALSE, ?, '{}'::jsonb, ?)
         ON CONFLICT (itemid) DO UPDATE SET
              itemprice = EXCLUDED.itemprice, itemstatus = 1",
        [$itemId, $name, $sku, $price, $taxId, $companyId]
    );
    (new ItemOutletService($db))->replace($itemId, $companyId, $outletIds);
}

verifyUpsertItem($ITEM_A, $PY_COMPANY, 'Verify outlet A', 'VERIFY-OUTLET-A', 1000, $TAX_ID, [$PY_OUTLET]);
verifyUpsertItem($ITEM_B, $PY_COMPANY, 'Verify outlet B', 'VERIFY-OUTLET-B', 2000, $TAX_ID, [$OUTLET_B]);
verifyUpsertItem($ITEM_GLOBAL, $PY_COMPANY, 'Verify outlet ambas', 'VERIFY-OUTLET-GLOBAL', 3000, $TAX_ID, [$PY_OUTLET, $OUTLET_B]);

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

// ── Caso 6: al ítem le SACAN la sucursal de la caja → lápida en el delta ───
// Es el caso central de la mig 170 y el más fácil de romper: el ítem NO se
// borró (sigue vivo para OUTLET_B), pero para la caja de PY_OUTLET dejó de
// existir. Si el delta solo lo omitiera de `items`, el device se quedaría con
// la copia vieja en cache — vendible — para siempre.
$sinceB = date('Y-m-d H:i:s', time() - 1);
sleep(1); // que el bump del trigger caiga DESPUÉS del watermark, no en el mismo segundo

// ITEM_GLOBAL estaba en las dos sucursales; se lo deja SOLO en OUTLET_B.
(new ItemOutletService($db))->replace($ITEM_GLOBAL, $PY_COMPANY, [$OUTLET_B]);

$deltaPy = $sync->itemsDelta($PY_COMPANY, $sinceB, $PY_OUTLET);
verifyOutletCheck(
    'caso 6a: la caja que PERDIÓ el ítem lo recibe en deletedIds (lápida de visibilidad)',
    in_array($ITEM_GLOBAL, $deltaPy['deletedIds'], true),
    'deletedIds no incluye el item que dejó de pertenecer a PY_OUTLET: ' . json_encode($deltaPy['deletedIds']),
    $failures
);
$deltaPyItemIds = array_map(static fn($r) => $r['itemId'] ?? null, $deltaPy['items']);
verifyOutletCheck(
    'caso 6b: y NO viene en items (no se re-siembra lo que se acaba de podar)',
    !in_array($ITEM_GLOBAL, $deltaPyItemIds, true),
    'el item apareció en items pese a no pertenecer ya a PY_OUTLET',
    $failures
);

// La otra caja conserva el ítem: no debe recibirlo como borrado, y sí como
// actualizado (el trigger de `item_outlet` le bumpeó `updated_at`).
$deltaB = $sync->itemsDelta($PY_COMPANY, $sinceB, $OUTLET_B);
verifyOutletCheck(
    'caso 6c: la caja que CONSERVA el ítem no lo recibe como borrado',
    !in_array($ITEM_GLOBAL, $deltaB['deletedIds'], true),
    'OUTLET_B recibió como borrado un item que sigue siendo suyo: ' . json_encode($deltaB['deletedIds']),
    $failures
);
$deltaBItemIds = array_map(static fn($r) => $r['itemId'] ?? null, $deltaB['items']);
verifyOutletCheck(
    'caso 6d: el trigger de item_outlet bumpeó updated_at — la caja que conserva el ítem lo ve actualizado',
    in_array($ITEM_GLOBAL, $deltaBItemIds, true),
    'OUTLET_B no vio el cambio: el trigger trg_item_outlet_touch_item no bumpeó item.updated_at',
    $failures
);

// El ítem ya no se ve desde PY_OUTLET por el filtro positivo tampoco.
verifyOutletCheck(
    'caso 6e: el listado de PY_OUTLET ya no muestra el ítem',
    !in_array($ITEM_GLOBAL, verifyFetchIds($allThree, $PY_COMPANY, $PY_OUTLET), true),
    'el listado de PY_OUTLET todavía muestra un item que ya no le pertenece',
    $failures
);

// ── Caso 7: invariante de mínimo UNA sucursal ──────────────────────────────
$emptyRejected = false;
try {
    (new ItemOutletService($db))->replace($ITEM_A, $PY_COMPANY, []);
} catch (\InvalidArgumentException $e) {
    $emptyRejected = true;
}
verifyOutletCheck(
    'caso 7: dejar un ítem sin ninguna sucursal es rechazado (invariante mínimo-una)',
    $emptyRejected,
    'replace() aceptó una lista vacía — el ítem quedaría invisible en toda caja',
    $failures
);
verifyOutletCheck(
    'caso 7b: y el rechazo no dejó el vínculo a medias (sigue en su sucursal)',
    (new ItemOutletService($db))->listFor($ITEM_A, $PY_COMPANY) === [$PY_OUTLET],
    'el DELETE corrió antes de validar: el item quedó sin sucursales',
    $failures
);

// ── Caso 8: aislamiento multi-tenant ───────────────────────────────────────
// UUID BIEN FORMADO (si no, el rechazo probaría un error de casteo de
// Postgres, no el chequeo de tenant) pero que no es sucursal de esta empresa.
$MX_OUTLET_FAKE = 'ab0e1e70-1111-4a1a-8b1b-000000000c01';
$foreignRejected = false;
try {
    (new ItemOutletService($db))->replace($ITEM_A, $PY_COMPANY, [$PY_OUTLET, $MX_OUTLET_FAKE]);
} catch (\InvalidArgumentException $e) {
    $foreignRejected = true;
}
verifyOutletCheck(
    'caso 8: una sucursal ajena al tenant en la lista aborta, nunca inserta',
    $foreignRejected,
    'replace() aceptó una sucursal que no pertenece a la empresa',
    $failures
);

// ── Caso 9: aislamiento multi-tenant en el WRITE de sucursales ─────────────
// `ItemService::update()` recibe el itemId del caller. Sin el guard de
// pertenencia, un PUT con el id de un ítem de OTRA empresa insertaría filas
// (itemid ajeno, outletid mío, companyid mío) en `item_outlet` — el UPDATE de
// columnas se protege solo con su WHERE, pero un INSERT no tiene dónde
// filtrar. Se usa el ítem de la company MX del seed contra la company PY.
$MX_COMPANY = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
$MX_ITEM    = '52b6ee53-3702-4127-a5a9-f31c8a75b938'; // 'Verify 16% incluido' (MX)

$outletsBefore = (new ItemOutletService($db))->listFor($MX_ITEM, $MX_COMPANY);
$crossTenantOk = $itemsItemService->update($MX_ITEM, $PY_COMPANY, ['outletIds' => [$PY_OUTLET]]);
$outletsAfter  = (new ItemOutletService($db))->listFor($MX_ITEM, $MX_COMPANY);

verifyOutletCheck(
    'caso 9a: update() de un ítem de OTRO tenant con outletIds devuelve false, no lo toca',
    $crossTenantOk === false,
    'update() devolvió true para un ítem que no pertenece a la company del caller',
    $failures
);
verifyOutletCheck(
    'caso 9b: y NO se insertó ninguna fila de item_outlet cruzada',
    $outletsAfter === $outletsBefore,
    'las sucursales del item ajeno cambiaron: antes ' . json_encode($outletsBefore)
        . ', después ' . json_encode($outletsAfter),
    $failures
);
$crossRows = $db->Execute(
    'SELECT COUNT(*) AS c FROM item_outlet WHERE itemid = ? AND companyid = ?',
    [$MX_ITEM, $PY_COMPANY]
);
$crossCount = $crossRows === false ? -1 : (int) ($crossRows->fields['c'] ?? -1);
verifyOutletCheck(
    'caso 9c: no quedó ninguna fila (itemid ajeno, companyid del atacante) en item_outlet',
    $crossCount === 0,
    "se encontraron {$crossCount} filas cruzadas en item_outlet",
    $failures
);

// ── Caso 10: el comodín legacy "todas" ya no se acepta ─────────────────────
// Un cliente viejo que mande `outletId: null` (que es lo que el front mandaba
// antes de la mig 170) NO debe ensanchar la visibilidad del ítem a todas las
// sucursales en silencio.
$wildcardRejected = false;
try {
    (new ItemOutletService($db))->resolveFromPayload(['outletId' => null], $PY_COMPANY);
} catch (\InvalidArgumentException $e) {
    $wildcardRejected = true;
}
verifyOutletCheck(
    'caso 10: `outletId: null` (comodín "todas" legacy) es rechazado, no traducido a todas las sucursales',
    $wildcardRejected,
    'un payload legacy con outletId vacío ensanchó la visibilidad en vez de fallar',
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
