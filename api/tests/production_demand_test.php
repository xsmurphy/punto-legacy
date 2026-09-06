<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del ALIMENTADOR DEL LOTE — de la cola de órdenes a las líneas
 * {plato, cantidad} (context/70-viandas.md, etapa B: "un clic del lote de
 * pedidos al lote de producción").
 *
 * Corre contra Postgres real, sin mocks: las órdenes se crean por el camino
 * REAL (`OrderCoreService::create()`) y los estados se mueven por
 * `updateItemStatus()`/`updateStatus()`, que es lo que hace el KDS.
 *
 * Lo que verifica, y por qué nada de esto se comprueba leyendo el código:
 *
 *   (A) DOS ÓRDENES DEL MISMO PLATO SUMAN. Es la pregunta textual del owner —
 *       "en lugar de calcular los ingredientes por cada orden, calculá a nivel
 *       macro". Un bug acá devuelve un número creíble y equivocado.
 *   (B) UN ÍTEM `ready` NO CUENTA. Ya se cocinó: traerlo mandaría a cocinar de
 *       nuevo lo que está en el pase. `preparing`, en cambio, SÍ cuenta — está
 *       empezado, no terminado.
 *   (C) UN ÍTEM CANCELADO NO CUENTA.
 *   (D) UNA ORDEN CANCELADA NO CUENTA, aunque sus líneas hayan quedado en
 *       `pending`. Cancelar la ORDEN toca `pos_order.status` y NO cascadea a
 *       los ítems, así que el filtro de cabecera no es redundante con el de
 *       línea: es el único que atrapa este caso.
 *   (E) LA HIJA DE ADD-ON ENTRA COMO LÍNEA PROPIA, con su `itemid`. El queso
 *       extra es una necesidad real de producción. Y —lo que este arnés
 *       PRUEBA en vez de asumir— la hija espeja el status del padre en la BD:
 *       bumpear al padre mueve a la hija en el mismo UPDATE, así que filtrar
 *       por el status de la LÍNEA la cubre sin caso especial.
 *   (F) LOS `sources` RECONSTRUYEN EL TOTAL. Sin eso la demanda consolidada es
 *       un número que el cocinero no puede auditar contra sus comandas.
 *   (G) ALCANCE POR SUCURSAL Y POR TENANT (D4). Una orden de otra sucursal del
 *       MISMO comercio no entra, y una de otro comercio tampoco.
 *   (H) UNA LÍNEA DE TEXTO LIBRE (sin `itemid`) NO ENTRA PERO SE CUENTA. No se
 *       puede armar una línea de lote con ella —no hay receta ni stock—, pero
 *       esconderla dejaría al cocinero creyendo que trajo toda la cola.
 *
 * Uso (ver `run_production_batch_test.sh` para levantar todo de cero):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/production_demand_test.php
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Orders/OrderCoreService.php';
require_once dirname(__DIR__) . '/lib/Orders/OrderDemandService.php';

use Punto\Api\Orders\OrderCoreService;
use Punto\Api\Orders\OrderDemandService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

// Tenant y sucursal ajenos (seed.sql, "Verify MX").
const OTHER_COMPANY  = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
const OTHER_OUTLET   = '6d3cab3a-c040-4428-8090-6790469de3bd';
const OTHER_REGISTER = 'e91e3e74-b593-4833-9ee8-25b8ce9e4454';

// Segunda sucursal del MISMO tenant — la crea este arnés (el seed trae una
// sola) para poder probar el alcance por sucursal del D4.
const PY_OUTLET_2 = 'de3a0002-0000-4000-8000-000000000001';

// Ítems propios del arnés — prefijo `de3a…` para no chocar con los del seed
// ni con los de `production_batch_test.php` (`ba7c…`).
const IT_MILANESA = 'de3a0001-0000-4000-8000-000000000001';
const IT_SOPA     = 'de3a0001-0000-4000-8000-000000000002';
const IT_QUESO    = 'de3a0001-0000-4000-8000-000000000003'; // opción de add-on

/** Marca de este arnés en `pos_order.channelref` — permite limpiar y re-correr. */
const MARK = 'demand-test';

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function near(float $a, float $b, float $eps = 1e-6): bool
{
    return abs($a - $b) < $eps;
}

/** Una línea del resultado, por itemId. */
function line(array $demand, string $itemId): ?array
{
    foreach ($demand['lines'] as $row) {
        if ($row['itemId'] === $itemId) {
            return $row;
        }
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Fixtures — idempotentes: el `.sh` recarga el tenant en cada corrida, pero el
// arnés tiene que poder correr dos veces seguidas contra la misma base.
// ─────────────────────────────────────────────────────────────────────────────

function upsertItem(string $id, string $name, string $sku, string $companyId): void
{
    ncmExecute(
        "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemcost, itemtype, itemstatus,
                           itemcansale, itemtrackinventory, itemproduction, data, companyid, itemkind)
         VALUES (?, ?, ?, 1000, 0, 'product', 1, TRUE, TRUE, TRUE, '{}'::jsonb, ?, 'produccion_previa')
         ON CONFLICT (itemid) DO UPDATE SET itemname = EXCLUDED.itemname, itemstatus = 1",
        [$id, $name, $sku, $companyId]
    );
}

upsertItem(IT_MILANESA, 'Demanda milanesa', 'DEM-MILA', $companyId);
upsertItem(IT_SOPA, 'Demanda sopa', 'DEM-SOPA', $companyId);
upsertItem(IT_QUESO, 'Demanda queso extra', 'DEM-QUESO', $companyId);

// Segunda sucursal PY + su depósito por defecto. El depósito no es adorno: la
// cadena Company > Sucursal > Depósito es un invariante del modelo
// (context/08) y `outlet_chain_invariant_test.php` marca como rota cualquier
// sucursal sin él — dejar una a medias acá rompería OTRO arnés.
ncmExecute(
    "INSERT INTO outlet (outletId, outletName, outletStatus, companyId)
     VALUES (?, 'Demanda - Sucursal 2', 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
    [PY_OUTLET_2, $companyId]
);
ncmExecute(
    "INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, outletId, taxonomyName, taxonomyExtra)
     SELECT 'de3a0003-0000-4000-8000-000000000001', ?, 'location', ?, 'Demanda - Depósito 2', '{\"isDefault\": true}'
      WHERE NOT EXISTS (SELECT 1 FROM taxonomy WHERE outletId = ? AND taxonomyType = 'location')",
    [$companyId, PY_OUTLET_2, PY_OUTLET_2]
);

// Limpieza de corridas previas. Las líneas caen por el ON DELETE CASCADE de
// `pos_order_item.orderid`; los eventos, por el suyo.
ncmExecute('DELETE FROM pos_order WHERE channelref = ?', [MARK]);

global $db;
$orders = new OrderCoreService($db);
$demand = new OrderDemandService();

/** Crea una orden marcada y devuelve su id. */
function mkOrder(
    OrderCoreService $orders,
    string $companyId,
    string $outletId,
    string $registerId,
    array $items
): string {
    return $orders->create($companyId, [
        'outletId'   => $outletId,
        'registerId' => $registerId,
        'source'     => 'counter',
        'channelRef' => MARK,
        'sendNow'    => true,
        'items'      => $items,
    ]);
}

/** El id de la línea de un producto dentro de una orden. */
function itemIdOf(string $companyId, string $orderId, string $itemId): string
{
    $row = ncmExecute(
        'SELECT orderitemid FROM pos_order_item
          WHERE orderid = ? AND companyid = ? AND itemid = ? AND parentorderitemid IS NULL
          LIMIT 1',
        [$orderId, $companyId, $itemId]
    );
    return (string) ($row['orderitemid'] ?? '');
}

// ─────────────────────────────────────────────────────────────────────────────
// La cola
// ─────────────────────────────────────────────────────────────────────────────

// O1 — 2 milanesas + 1 sopa, todo pending.
$o1 = mkOrder($orders, $companyId, $outletId, $registerId, [
    ['itemId' => IT_MILANESA, 'qty' => 2],
    ['itemId' => IT_SOPA,     'qty' => 1],
]);

// O2 — 3 milanesas, movidas a `preparing`: empezadas, no terminadas → cuentan.
$o2 = mkOrder($orders, $companyId, $outletId, $registerId, [
    ['itemId' => IT_MILANESA, 'qty' => 3],
]);
$orders->updateItemStatus($companyId, itemIdOf($companyId, $o2, IT_MILANESA), 'preparing');

// O3 — 7 milanesas ya `ready`: NO cuentan.
$o3 = mkOrder($orders, $companyId, $outletId, $registerId, [
    ['itemId' => IT_MILANESA, 'qty' => 7],
]);
$o3Item = itemIdOf($companyId, $o3, IT_MILANESA);
$orders->updateItemStatus($companyId, $o3Item, 'preparing');
$orders->updateItemStatus($companyId, $o3Item, 'ready');

// O4 — 11 milanesas anuladas a nivel LÍNEA: NO cuentan. La orden lleva otra
// línea viva (1 sopa) para que la orden siga siendo no-terminal y el caso
// aísle de verdad el filtro de línea.
$o4 = mkOrder($orders, $companyId, $outletId, $registerId, [
    ['itemId' => IT_MILANESA, 'qty' => 11],
    ['itemId' => IT_SOPA,     'qty' => 1],
]);
$orders->updateItemStatus($companyId, itemIdOf($companyId, $o4, IT_MILANESA), 'cancelled', null, 'arnés: línea anulada');

// O5 — 13 milanesas en una ORDEN cancelada: NO cuentan. Sus líneas quedan en
// `pending` (cancelar la orden no cascadea a los ítems) — este es el caso que
// solo atrapa el filtro de cabecera.
$o5 = mkOrder($orders, $companyId, $outletId, $registerId, [
    ['itemId' => IT_MILANESA, 'qty' => 13],
]);
$orders->updateStatus($companyId, $o5, 'cancelled', null, 'arnés: orden cancelada');

// O6 — 1 milanesa con una hija de add-on (1 queso extra). La hija se inserta
// con el MISMO shape que produce `OrderCoreService::create()` (price 0, el
// recargo en `pricedelta`, estación y course heredados) en vez de armar
// grupos/opciones de add-on en el catálogo: lo que este arnés prueba es el
// AGREGADOR, y el mecanismo de add-ons ya tiene el suyo
// (`verify_addon_stock.php`). El fixture replica el estado de la BD, no el
// camino que lo genera.
$o6 = mkOrder($orders, $companyId, $outletId, $registerId, [
    ['itemId' => IT_MILANESA, 'qty' => 1],
]);
$o6Parent = itemIdOf($companyId, $o6, IT_MILANESA);
$o6Child  = generateUuidV7();
ncmExecute(
    'INSERT INTO pos_order_item
        (orderitemid, orderid, companyid, itemid, name, qty, price, stationid, course,
         parentorderitemid, addonoptionid, pricedelta)
     VALUES (?, ?, ?, ?, ?, 1, 0, NULL, 1, ?, ?, 500)',
    [$o6Child, $o6, $companyId, IT_QUESO, 'Demanda queso extra', $o6Parent, generateUuidV7()]
);

// O7 — una línea de TEXTO LIBRE, sin producto de catálogo.
$o7 = mkOrder($orders, $companyId, $outletId, $registerId, [
    ['name' => 'Lo de siempre para la mesa 4', 'qty' => 1, 'price' => 20000],
]);

// O8 — 100 milanesas en OTRA sucursal del mismo tenant: fuera del alcance.
$o8 = mkOrder($orders, $companyId, PY_OUTLET_2, $registerId, [
    ['itemId' => IT_MILANESA, 'qty' => 100],
]);

// O9 — otro tenant, con su propio ítem. No debe filtrarse por ningún lado.
upsertItem('de3a0001-0000-4000-8000-0000000000ff', 'Demanda ajena', 'DEM-AJENA', OTHER_COMPANY);
$o9 = mkOrder($orders, OTHER_COMPANY, OTHER_OUTLET, OTHER_REGISTER, [
    ['itemId' => 'de3a0001-0000-4000-8000-0000000000ff', 'qty' => 50],
]);

// ─────────────────────────────────────────────────────────────────────────────
// Lo que la pantalla del lote va a traer
// ─────────────────────────────────────────────────────────────────────────────

echo "\n=== demanda de la cola ===\n";
$d = $demand->pendingByItem($companyId, $outletId);

// (A) 2 (O1) + 3 (O2, preparing) + 1 (O6) = 6. Ni 7 (ready), ni 11
// (cancelada), ni 13 (orden cancelada), ni 100 (otra sucursal).
$mila = line($d, IT_MILANESA);
check('(A) dos órdenes del mismo plato suman, y `preparing` cuenta',
    $mila !== null && near((float) $mila['qty'], 6.0),
    'milanesa = ' . json_encode($mila['qty'] ?? null) . ' (esperado 6)', $failures, $checks);

// (B)(C)(D) quedan probados por el total de (A), pero se afirman uno por uno
// para que un fallo diga CUÁL de los filtros se rompió.
check('(B) el ítem `ready` no suma',
    $mila !== null && !in_array($o3, array_column($mila['sources'], 'orderId'), true),
    'la orden con la línea ready aparece en sources', $failures, $checks);
check('(C) el ítem anulado no suma',
    $mila !== null && !in_array($o4, array_column($mila['sources'], 'orderId'), true),
    'la orden con la línea anulada aparece en sources', $failures, $checks);
check('(D) la orden cancelada no suma (sus líneas siguen en pending)',
    $mila !== null && !in_array($o5, array_column($mila['sources'], 'orderId'), true),
    'la orden cancelada aparece en sources', $failures, $checks);

// La sopa: 1 de O1 + 1 de O4 (la línea viva de la orden con otra línea anulada).
$sopa = line($d, IT_SOPA);
check('(C2) anular UNA línea no se lleva a las otras de la misma orden',
    $sopa !== null && near((float) $sopa['qty'], 2.0),
    'sopa = ' . json_encode($sopa['qty'] ?? null) . ' (esperado 2)', $failures, $checks);

// (E) La hija de add-on, como línea propia.
$queso = line($d, IT_QUESO);
check('(E1) la hija de add-on entra como línea propia con su itemId',
    $queso !== null && near((float) $queso['qty'], 1.0),
    'queso = ' . json_encode($queso['qty'] ?? null) . ' (esperado 1)', $failures, $checks);

// (E2) La afirmación que el diseño da por sentada: la hija VIAJA con el padre
// también en la BD. Se bumpea el PADRE a ready y la hija tiene que salir de la
// demanda sola — sin que nadie la toque.
$orders->updateItemStatus($companyId, $o6Parent, 'preparing');
$orders->updateItemStatus($companyId, $o6Parent, 'ready');
$d2 = $demand->pendingByItem($companyId, $outletId);
check('(E2) bumpear al padre saca a la hija de la demanda (espeja status en BD)',
    line($d2, IT_QUESO) === null,
    'el queso sigue en la demanda con el padre en ready', $failures, $checks);
check('(E3) …y el padre también sale: la milanesa baja de 6 a 5',
    line($d2, IT_MILANESA) !== null && near((float) line($d2, IT_MILANESA)['qty'], 5.0),
    'milanesa = ' . json_encode(line($d2, IT_MILANESA)['qty'] ?? null) . ' (esperado 5)', $failures, $checks);

// (F) Los sources reconstruyen el total, y traen el número de orden.
$sourcesSum = array_sum(array_map(static fn (array $s): float => (float) $s['qty'], $mila['sources'] ?? []));
check('(F1) los sources de la milanesa reconstruyen su total',
    near($sourcesSum, (float) ($mila['qty'] ?? -1)),
    'suma de sources = ' . $sourcesSum . ', total = ' . json_encode($mila['qty'] ?? null), $failures, $checks);
check('(F2) son 3 órdenes distintas (O1, O2, O6) con su ordernumber',
    count($mila['sources'] ?? []) === 3
        && count(array_unique(array_column($mila['sources'], 'orderId'))) === 3
        && count(array_filter($mila['sources'], static fn (array $s): bool => $s['orderNumber'] !== null)) === 3,
    json_encode($mila['sources'] ?? null), $failures, $checks);
check('(F3) el source de O1 trae 2 y el de O2 trae 3 — no un promedio ni el total',
    (static function () use ($mila, $o1, $o2): bool {
        $by = [];
        foreach ($mila['sources'] ?? [] as $s) { $by[$s['orderId']] = (float) $s['qty']; }
        return near($by[$o1] ?? -1, 2.0) && near($by[$o2] ?? -1, 3.0);
    })(),
    json_encode($mila['sources'] ?? null), $failures, $checks);

// (G) Alcance.
check('(G1) la orden de la otra sucursal no entra (D4)',
    $mila !== null && !in_array($o8, array_column($mila['sources'], 'orderId'), true),
    'la orden de la sucursal 2 aparece en sources', $failures, $checks);
$dOtro = $demand->pendingByItem(OTHER_COMPANY, OTHER_OUTLET);
check('(G2) el otro tenant ve SOLO lo suyo',
    count($dOtro['lines']) === 1 && $dOtro['lines'][0]['itemId'] === 'de3a0001-0000-4000-8000-0000000000ff',
    json_encode(array_column($dOtro['lines'], 'itemId')), $failures, $checks);
check('(G3) …y nuestra cola no trae nada del otro tenant',
    !in_array('de3a0001-0000-4000-8000-0000000000ff', array_column($d['lines'], 'itemId'), true),
    json_encode(array_column($d['lines'], 'itemId')), $failures, $checks);
check('(G4) un outletId de otro tenant se rechaza, no devuelve vacío',
    (static function () use ($demand, $companyId): bool {
        try { $demand->pendingByItem($companyId, OTHER_OUTLET); return false; }
        catch (\Throwable $e) { return true; }
    })(),
    'pendingByItem() con un outlet ajeno no lanzó', $failures, $checks);

// (H) Texto libre: afuera de las líneas, pero contado.
check('(H1) la línea de texto libre no entra como línea del lote',
    count(array_filter($d['lines'], static fn (array $l): bool => $l['itemId'] === '' || $l['itemId'] === null)) === 0,
    json_encode(array_column($d['lines'], 'itemId')), $failures, $checks);
check('(H2) …pero se cuenta y se devuelve (no se esconde)',
    ($d['skippedFreeText'] ?? 0) === 1,
    'skippedFreeText = ' . json_encode($d['skippedFreeText'] ?? null) . ' (esperado 1)', $failures, $checks);

// Metadatos de la FOTO (D2).
check('(I1) la respuesta trae `takenAt` en el reloj del comercio',
    isset($d['takenAt']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $d['takenAt']) === 1,
    'takenAt = ' . json_encode($d['takenAt'] ?? null), $failures, $checks);
check('(I2) `orderCount` cuenta las órdenes que aportaron, no las líneas',
    ($d['orderCount'] ?? 0) === 4,
    'orderCount = ' . json_encode($d['orderCount'] ?? null) . ' (esperado 4: O1, O2, O4, O6)', $failures, $checks);
check('(I3) `truncated` es false con una cola chica',
    ($d['truncated'] ?? true) === false,
    'truncated = ' . json_encode($d['truncated'] ?? null), $failures, $checks);

// Sin cola, la respuesta es vacía y honesta — no un error.
ncmExecute('DELETE FROM pos_order WHERE channelref = ?', [MARK]);
$vacio = $demand->pendingByItem($companyId, $outletId);
check('(J) sin órdenes pendientes devuelve una cola vacía, no un error',
    $vacio['lines'] === [] && $vacio['orderCount'] === 0 && $vacio['skippedFreeText'] === 0,
    json_encode($vacio), $failures, $checks);

harnessFinish($failures, $checks);
