<?php
declare(strict_types=1);

/**
 * Test de integración (DB real) de `ReturnService::create()` /
 * `ReturnService::returnOptions()` — D2 (reposición de stock, wrapper
 * `StockReversalPolicy` compartido con `SaleVoidService`) y D3 (política de
 * reintegro `settingReturnRefund`) de context/40-anulacion-y-nota-credito.md.
 *
 * Mismo patrón que `sale_void_test.php`: reusa el tenant fixture "Verify PY"
 * (`api/lib/Sales/verify_chain/seed.sql`), inserta sus propias ventas por SQL
 * directo, corre contra Postgres real (lockea con `FOR UPDATE`, depende de
 * `Inventory::manageStock()`/`explodeRecipe()` y `TransactionLinkService`
 * leyendo datos reales).
 *
 * A diferencia de `SaleVoidService::void()`, `ReturnService::create()` nunca
 * llama `apiError()` (siempre tira excepciones catcheables) — no hace falta
 * el patrón de subproceso que usa `sale_void_test.php` para sus casos de
 * rechazo.
 *
 * Ítems reusados del fixture (ver seed.sql / sale_void_test.php):
 *   - stockItemId  "Verify stock trackeable" (itemTrackInventory=TRUE, sin
 *     receta) → kind='ownStock'.
 *   - prodDirectId "Verify producción directa" (itemTrackInventory=FALSE,
 *     receta 2× insumo) → kind='ingredientReversal'.
 *   - serviceItemId "Verify 10% incluido" (sin stock, sin receta) → kind='service'.
 *
 * Casos:
 *   (a) D2 happy path: devolución de 2 líneas — stock propio con
 *       restock=true (repone) + producción directa sin restock (default
 *       false → waste_event, insumo NO repuesto por setting apagado).
 *   (b) D2: restock=true pedido sobre una línea canRestock=false (producción
 *       directa con el setting apagado) se IGNORA en silencio (clamp, mismo
 *       criterio que SaleVoidService) — no 422, genera waste igual.
 *   (c) D3: settingReturnRefund='cash' + request refundMode='credit' → rechazo.
 *   (d) D3: settingReturnRefund='credit' + venta sin cliente → cae a 'cash'
 *       en vez de fallar.
 *   (e) returnOptions(): soldQty/alreadyReturned/availableQty correctos
 *       antes y después de una devolución parcial.
 *   (f) invariante financiero: una devolución TOTAL de una línea devuelve
 *       exactamente lo que esa línea vendió (sin arrastre de redondeo por
 *       computar el unitario con precisión completa).
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_return_d2_d3_test.sh` para levantar todo de cero en Docker):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/return_d2_d3_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\ReturnService;
use Punto\App\Domain\Inventory;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$customerId = '2b9f6a71-3e2b-4b34-9b5a-7a6a6a6a6a6a'; // "Verify PY Cliente sin credito" — solo se usa como cliente identificado, no depende de contactCreditable acá.
$roleId     = '1';
require API_APP_DIR . '/data.php';

$stockItemId   = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f'; // ownStock
$prodDirectId  = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6b'; // ingredientReversal (receta 2x insumo)
$insumoId      = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6a'; // insumo de la receta de arriba
$serviceItemId = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // service (sin stock, sin receta)

$failures = 0;

function check(string $label, bool $ok, string $detail, int &$failures): void
{
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/**
 * Inserta una venta contado (type=0) mínima, propia de este test.
 *
 * @param list<array{itemId:string,units:float,total:float,cogs:float}> $lines
 */
function makeSale(
    string  $companyId,
    string  $outletId,
    string  $registerId,
    string  $userId,
    array   $lines,
    ?string $customerId = null
): string {
    global $db;

    $total = array_sum(array_column($lines, 'total'));
    $units = array_sum(array_column($lines, 'units'));
    $date  = date('Y-m-d H:i:s');

    $db->AutoExecute('transaction', [
        'transactionTotal'       => $total,
        'transactionDiscount'    => 0,
        'transactionUnitsSold'   => $units,
        'transactionType'        => 0,
        'transactionComplete'    => true,
        'transactionStatus'      => 1,
        'transactionDate'        => $date,
        'transactionPaymentType' => json_encode([['type' => 'cash', 'price' => $total, 'total' => $total]]),
        'invoiceNo'              => random_int(1000000, 9999999),
        'timestamp'              => time(),
        'registerId'             => $registerId,
        'userId'                 => $userId,
        'responsibleId'          => $userId,
        'outletId'               => $outletId,
        'companyId'              => $companyId,
        'customerId'             => $customerId,
    ], 'INSERT');
    $transactionId = (string) $db->Insert_ID();

    foreach ($lines as $line) {
        $db->AutoExecute('itemSold', [
            'itemId'         => $line['itemId'],
            'transactionId'  => $transactionId,
            'itemSoldUnits'  => $line['units'],
            'itemSoldTotal'  => $line['total'],
            'itemSoldCOGS'   => $line['cogs'],
            'itemSoldDate'   => $date,
        ], 'INSERT');
    }

    return $transactionId;
}

function setReturnRefundPolicy(string $companyId, ?string $val): void
{
    global $db;
    if ($val === null) {
        $db->Execute("UPDATE company SET config = config - 'settingReturnRefund' WHERE companyid = ?", [$companyId]);
    } else {
        $db->Execute("UPDATE company SET config = jsonb_set(config, '{settingReturnRefund}', to_jsonb(?::text)) WHERE companyid = ?", [$val, $companyId]);
    }
}

$svc = new ReturnService();

// ── (a) D2 happy path ───────────────────────────────────────────────────
$stockBefore  = Inventory::onHand($stockItemId, $outletId);
$insumoBefore = Inventory::onHand($insumoId, $outletId);

$saleA = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $stockItemId,  'units' => 3, 'total' => 3000,  'cogs' => 500],
    ['itemId' => $prodDirectId, 'units' => 2, 'total' => 18000, 'cogs' => 1000],
]);

$resultA = $svc->create(
    $companyId, $userId, $outletId, $registerId, $saleA,
    [
        ['itemId' => $stockItemId,  'qty' => 3, 'restock' => true],
        ['itemId' => $prodDirectId, 'qty' => 2],
    ],
    'cash',
    'test (a)'
);

check('(a) create() repone 1 línea (stock propio) y genera 1 waste (producción directa)', $resultA['stockMovements'] === 1 && $resultA['wasted'] === 1, json_encode($resultA), $failures);

$stockAfter  = Inventory::onHand($stockItemId, $outletId);
$insumoAfter = Inventory::onHand($insumoId, $outletId);
check('(a) stock propio repuesto (+3)', abs(($stockAfter - $stockBefore) - 3.0) < 0.001, "before=$stockBefore after=$stockAfter", $failures);
check('(a) insumo de producción directa NO repuesto (setting apagado por default)', abs($insumoAfter - $insumoBefore) < 0.001, "before=$insumoBefore after=$insumoAfter", $failures);

$wasteA = ncmExecute(
    "SELECT qty, cost FROM waste_event WHERE companyid = ? AND itemid = ? AND note LIKE 'Devolución de cliente%' ORDER BY created_at DESC LIMIT 1",
    [$companyId, $prodDirectId]
);
check('(a) waste_event generado para la línea no repuesta, con costo correcto', $wasteA && abs((float) $wasteA['qty'] - 2.0) < 0.001 && abs((float) $wasteA['cost'] - 2000.0) < 0.01, json_encode($wasteA), $failures);

// ── (b) restock=true pedido sobre línea canRestock=false se ignora (clamp) ──
$saleB = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $prodDirectId, 'units' => 1, 'total' => 9000, 'cogs' => 1000],
]);
$insumoBeforeB = Inventory::onHand($insumoId, $outletId);

$resultB = $svc->create(
    $companyId, $userId, $outletId, $registerId, $saleB,
    [['itemId' => $prodDirectId, 'qty' => 1, 'restock' => true]], // pedido explícito, pero canRestock=false
    'cash',
    'test (b)'
);
$insumoAfterB = Inventory::onHand($insumoId, $outletId);

check(
    '(b) restock=true sobre línea canRestock=false se ignora (no repone, genera waste igual — sin error)',
    $resultB['stockMovements'] === 0 && $resultB['wasted'] === 1 && abs($insumoAfterB - $insumoBeforeB) < 0.001,
    json_encode($resultB) . " insumoBefore=$insumoBeforeB insumoAfter=$insumoAfterB",
    $failures
);

// ── (c) D3: política 'cash' + request 'credit' se rechaza ─────────────────
setReturnRefundPolicy($companyId, 'cash');
$saleC = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $serviceItemId, 'units' => 1, 'total' => 4000, 'cogs' => 0],
], $customerId);

$caughtC = null;
try {
    $svc->create($companyId, $userId, $outletId, $registerId, $saleC, [['itemId' => $serviceItemId, 'qty' => 1]], 'credit', 'test (c)');
} catch (\InvalidArgumentException $e) {
    $caughtC = $e;
}
check(
    "(c) settingReturnRefund='cash' + request 'credit' se rechaza (InvalidArgumentException, mensaje menciona la política)",
    $caughtC !== null && str_contains($caughtC->getMessage(), 'settingReturnRefund'),
    $caughtC ? $caughtC->getMessage() : 'no se lanzó excepción',
    $failures
);
setReturnRefundPolicy($companyId, null);

// ── (d) D3: política 'credit' forzada + venta SIN cliente cae a 'cash' ────
setReturnRefundPolicy($companyId, 'credit');
$saleD = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $serviceItemId, 'units' => 1, 'total' => 6000, 'cogs' => 0],
]); // sin customerId

$resultD = null;
$caughtD = null;
try {
    $resultD = $svc->create($companyId, $userId, $outletId, $registerId, $saleD, [['itemId' => $serviceItemId, 'qty' => 1]], 'credit', 'test (d)');
} catch (\Throwable $e) {
    $caughtD = $e;
}
check(
    "(d) política 'credit' forzada + venta sin cliente NO falla, cae a 'cash'",
    $caughtD === null && $resultD !== null && $resultD['refundMode'] === 'cash',
    $caughtD ? $caughtD->getMessage() : json_encode($resultD),
    $failures
);
setReturnRefundPolicy($companyId, null);

// ── (e) returnOptions(): soldQty/alreadyReturned/availableQty ─────────────
$saleE = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $stockItemId, 'units' => 5, 'total' => 5000, 'cogs' => 500],
]);

$optionsBefore = $svc->returnOptions($companyId, $saleE);
$lineBefore = null;
foreach ($optionsBefore as $l) {
    if ($l['itemId'] === $stockItemId) { $lineBefore = $l; break; }
}
check(
    '(e) returnOptions() antes de devolver: soldQty=5, alreadyReturned=0, availableQty=5, canRestock=true, kind=ownStock',
    $lineBefore
        && abs($lineBefore['soldQty'] - 5.0) < 0.001
        && abs($lineBefore['alreadyReturned'] - 0.0) < 0.001
        && abs($lineBefore['availableQty'] - 5.0) < 0.001
        && $lineBefore['canRestock'] === true
        && $lineBefore['kind'] === 'ownStock',
    json_encode($lineBefore),
    $failures
);

$svc->create($companyId, $userId, $outletId, $registerId, $saleE, [['itemId' => $stockItemId, 'qty' => 2, 'restock' => true]], 'cash', 'test (e) parcial');

$optionsAfter = $svc->returnOptions($companyId, $saleE);
$lineAfter = null;
foreach ($optionsAfter as $l) {
    if ($l['itemId'] === $stockItemId) { $lineAfter = $l; break; }
}
check(
    '(e) returnOptions() tras devolución parcial (2 de 5): alreadyReturned=2, availableQty=3',
    $lineAfter && abs($lineAfter['alreadyReturned'] - 2.0) < 0.001 && abs($lineAfter['availableQty'] - 3.0) < 0.001,
    json_encode($lineAfter),
    $failures
);

// ── (f) invariante financiero: devolución TOTAL de una línea = su venta ───
$saleF = makeSale($companyId, $outletId, $registerId, $userId, [
    ['itemId' => $stockItemId, 'units' => 7, 'total' => 23333, 'cogs' => 333], // total no divide exacto por unidad, a propósito
]);
$resultF = $svc->create($companyId, $userId, $outletId, $registerId, $saleF, [['itemId' => $stockItemId, 'qty' => 7, 'restock' => true]], 'cash', 'test (f)');
check(
    '(f) devolución total de la línea devuelve EXACTAMENTE lo que esa línea vendió (23333), sin arrastre de redondeo',
    abs($resultF['total'] - 23333.0) < 0.01,
    json_encode($resultF),
    $failures
);

if ($failures > 0) {
    echo "\n$failures caso(s) fallido(s).\n";
    exit(1);
}
echo "\nTodos los casos OK.\n";
exit(0);
