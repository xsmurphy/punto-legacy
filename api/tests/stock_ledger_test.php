<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (DB real) del saneamiento de stock —
 * context/52-stock-ledger-unica-fuente.md.
 *
 * Cubre los tres invariantes que el plan cierra y que ningún arnés previo
 * ejercitaba:
 *
 *   (1) COMBO FIJO + ANULACIÓN, sin doble reposición (G4). La venta de un
 *       combo persiste una línea HIJA por componente (`meta.compound`, F6 de
 *       context/41) que NUNCA descuenta stock — el stock lo descuenta el
 *       PADRE explotando la receta. Antes, la reversa clasificaba esa hija
 *       como 'ownStock' y le acreditaba unidades que jamás se restaron,
 *       ADEMÁS de reponer el insumo vía el padre: el ingrediente terminaba
 *       con MÁS stock del que tenía antes de vender.
 *       (1a) setting de reversión de insumos APAGADO (default) → la anulación
 *            no repone nada y el saldo del ingrediente queda como después de
 *            la venta. Con el bug vivo subía +2 (la hija).
 *       (1b) setting ENCENDIDO + el cajero pide reponer → el saldo vuelve
 *            EXACTAMENTE al inicial. Con el bug vivo se pasaba al doble.
 *
 *   (2) MOVIMIENTO BACKDATED → `Inventory::onHand()` (SUM con signo) da el
 *       saldo correcto, y los LECTORES MIGRADOS coinciden con él: el reporte
 *       de niveles de stock (`Reports\StockService`) y el desglose por
 *       depósito (`Inventory::onHandByLocation`). Es el caso donde el
 *       snapshot `stockOnHand` de la última fila y el SUM divergen — el "bug
 *       del salmón" — y el que hacía que panel y POS mostraran números
 *       distintos del mismo ítem.
 *
 *   (3) CONTRATO DE RETORNO de `Inventory::manageStock()` (G11):
 *       (3a) ítem que no trackea inventario → devuelve `false` SIN lanzar
 *            (no-op legítimo, no un error).
 *       (3b) escritura que falla de verdad → LANZA. `false` ya no puede
 *            significar "se perdió el movimiento".
 *
 * Ítems del fixture "Verify PY" (`api/lib/Sales/verify_chain/seed.sql`):
 *   - comboId    'c0b1a5f2-…-5b70' "Verify combo fijo" (itemType='combo',
 *     receta 2× stockItemId) → la venta explota su receta.
 *   - stockItemId '7a1c1a9e-…-4e5f' "Verify stock trackeable" — el
 *     ingrediente del combo Y el itemId de la línea hija.
 *   - noTrackId  '10223f3b-…' "Verify 10% incluido" (itemTrackInventory=FALSE).
 *
 * Las ventas se insertan por SQL directo replicando EXACTAMENTE la forma que
 * persiste `SaleService` para un combo fijo (línea padre + línea hija con
 * `meta.compound`, precio 0 y COGS NULL) y descontando el stock con la misma
 * `Inventory::explodeRecipe()` que usa la venta — mismo criterio que
 * `sale_void_test.php` y `return_d2_d3_test.php`, que tampoco pasan por el
 * motor de venta completo. Lo que se ejercita acá es la REVERSA.
 *
 * Uso (necesita Postgres migrado + seed.sql cargado — ver
 * `run_stock_ledger_test.sh` para levantar todo de cero en Docker):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/stock_ledger_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla. La señal canónica
 * que lee el runner es la línea `HARNESS RESULT: … -> OK|FAIL`.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\SaleVoidService;
use Punto\App\Domain\Inventory;

// ── Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) ──
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$roleId     = '1';
require API_APP_DIR . '/data.php';

$comboId     = 'c0b1a5f2-6c3d-4e21-9a8b-1f2e3d4c5b70'; // combo fijo, receta 2× stockItemId
$stockItemId = '7a1c1a9e-3b1a-4e7b-8f7a-9a2b8c1d4e5f'; // ingrediente trackeable
$noTrackId   = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // itemTrackInventory = FALSE

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

/** Habilita/apaga `settingReturnAllowIngredientReversal` del tenant. */
function setIngredientReversal(string $companyId, bool $on): void
{
    global $db;
    if ($on) {
        $db->Execute(
            "UPDATE company SET config = COALESCE(config, '{}'::jsonb) || '{\"settingReturnAllowIngredientReversal\":\"yes\"}'::jsonb WHERE companyid = ?",
            [$companyId]
        );
    } else {
        $db->Execute("UPDATE company SET config = config - 'settingReturnAllowIngredientReversal' WHERE companyid = ?", [$companyId]);
    }
}

/**
 * Venta contado (type=0) de UN combo fijo, con la MISMA forma que persiste
 * `SaleService`: línea padre + una línea hija por componente de la receta,
 * con `meta.compound` (precio 0, COGS NULL) — y el descuento de stock que la
 * venta hace por el PADRE (explosión recursiva de la receta), nunca por las
 * hijas.
 *
 * @return array{0:string,1:string,2:string} [transactionId, parentSoldId, childSoldId]
 */
function makeComboSale(
    string $companyId,
    string $outletId,
    string $registerId,
    string $userId,
    string $comboId,
    float  $units,
    float  $total
): array {
    global $db;

    $date = date('Y-m-d H:i:s');

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
    ], 'INSERT');
    $transactionId = (string) $db->Insert_ID();

    // Línea PADRE (el combo).
    $db->AutoExecute('itemSold', [
        'itemId'        => $comboId,
        'transactionId' => $transactionId,
        'itemSoldUnits' => $units,
        'itemSoldTotal' => $total,
        'itemSoldCOGS'  => 1000,
        'itemSoldDate'  => $date,
        'companyId'     => $companyId,
        'outletId'      => $outletId,
        'registerId'    => $registerId,
    ], 'INSERT');
    $parentSoldId = (string) $db->Insert_ID();

    // Líneas HIJAS: una por componente de la receta. Precio 0 y COGS NULL
    // (omitido) — el costo real vive en el padre. `meta.compound` es el
    // discriminante que la reversa usa para NO reponerlas.
    $childSoldId = '';
    $recipe = Inventory::getCompoundsArray($comboId);
    foreach ((array) $recipe as $comp) {
        $childId    = (string) ($comp['compoundId'] ?? '');
        $childUnits = (float) ($comp['toCompoundQty'] ?? 0) * $units;
        if ($childId === '' || $childUnits <= 0) {
            continue;
        }
        $db->AutoExecute('itemSold', [
            'itemId'        => $childId,
            'transactionId' => $transactionId,
            'itemSoldUnits' => $childUnits,
            'itemSoldTotal' => 0,
            'itemSoldDate'  => $date,
            'companyId'     => $companyId,
            'outletId'      => $outletId,
            'registerId'    => $registerId,
            'meta'          => json_encode(['compound' => ['parentItemSoldId' => $parentSoldId]]),
        ], 'INSERT');
        $childSoldId = (string) $db->Insert_ID();
    }

    // Descuento de stock: SOLO por el padre, explosión recursiva — idéntico a
    // `SaleService::persistItemsAndStock()`, que saltea las hijas con
    // `continue`.
    foreach ((array) Inventory::explodeRecipe($comboId, $companyId, $units) as $leafId => $leafQty) {
        if ((float) $leafQty <= 0) {
            continue;
        }
        Inventory::manageStock([
            'itemId'        => (string) $leafId,
            'outletId'      => $outletId,
            'date'          => $date,
            'locationId'    => null,
            'count'         => (float) $leafQty,
            'type'          => '-',
            'source'        => 'sale',
            'transactionId' => $transactionId,
            'userId'        => $userId,
            'companyId'     => $companyId,
        ]);
    }

    return [$transactionId, $parentSoldId, $childSoldId];
}

$voidSvc = new SaleVoidService();

// ═══════════════════════════════════════════════════════════════════════════
// (1) Combo fijo + anulación: cero doble reposición
// ═══════════════════════════════════════════════════════════════════════════

// ── (1a) setting APAGADO: la anulación no repone nada ──────────────────────
setIngredientReversal($companyId, false);

// Stock de arranque suficiente para vender combos sin quedar negativo.
Inventory::manageStock([
    'itemId'    => $stockItemId,
    'outletId'  => $outletId,
    'date'      => date('Y-m-d H:i:s'),
    'count'     => 50,
    'type'      => '+',
    'source'    => 'adjustment',
    'note'      => 'stock_ledger_test: base',
    'userId'    => $userId,
    'companyId' => $companyId,
    'locationId' => null,
    'transactionId' => null,
]);

$initialA = Inventory::onHand($stockItemId, $outletId);

[$saleA, $parentSoldA] = makeComboSale($companyId, $outletId, $registerId, $userId, $comboId, 1, 25000);

$afterSaleA = Inventory::onHand($stockItemId, $outletId);
check(
    '(1a) la venta del combo descuenta SOLO por el padre (−2), la hija no mueve stock',
    abs(($initialA - $afterSaleA) - 2.0) < 0.001,
    "initial=$initialA afterSale=$afterSaleA (esperado −2)",
    $failures,
    $checks
);

// La clasificación de la hija es el corazón del fix: si vuelve 'ownStock', la
// UI de anulación le ofrece al cajero reponer stock que nunca se descontó.
$optionsA = $voidSvc->voidOptions($companyId, $saleA);
$childOpt = null;
foreach ($optionsA as $o) {
    if ($o['itemId'] === $stockItemId) { $childOpt = $o; break; }
}
check(
    '(1a) voidOptions(): la hija de combo se clasifica compoundChild, canRestock=false, hadStockImpact=false',
    $childOpt !== null
        && $childOpt['kind'] === 'compoundChild'
        && $childOpt['canRestock'] === false
        && $childOpt['hadStockImpact'] === false,
    json_encode($childOpt),
    $failures,
    $checks
);

$voidSvc->void($companyId, $saleA, $userId, 'stock_ledger_test (1a)', [], $registerId, $outletId);

$afterVoidA = Inventory::onHand($stockItemId, $outletId);
check(
    '(1a) anulación con reversión de insumos APAGADA: el saldo del ingrediente NO se mueve (con el bug subía +2)',
    abs($afterVoidA - $afterSaleA) < 0.001,
    "afterSale=$afterSaleA afterVoid=$afterVoidA",
    $failures,
    $checks
);

// ── (1b) setting ENCENDIDO + el cajero pide reponer ────────────────────────
setIngredientReversal($companyId, true);

$initialB = Inventory::onHand($stockItemId, $outletId);

[$saleB, $parentSoldB] = makeComboSale($companyId, $outletId, $registerId, $userId, $comboId, 3, 75000);

$afterSaleB = Inventory::onHand($stockItemId, $outletId);
check(
    '(1b) la venta de 3 combos descuenta 6 unidades del ingrediente',
    abs(($initialB - $afterSaleB) - 6.0) < 0.001,
    "initial=$initialB afterSale=$afterSaleB (esperado −6)",
    $failures,
    $checks
);

$voidSvc->void(
    $companyId,
    $saleB,
    $userId,
    'stock_ledger_test (1b)',
    // Solo la línea PADRE se repone; la hija ni se pide (y aunque se pidiera,
    // canRestock=false la clampea).
    [['itemSoldId' => $parentSoldB, 'itemId' => $comboId, 'restock' => true]],
    $registerId,
    $outletId
);

$afterVoidB = Inventory::onHand($stockItemId, $outletId);
check(
    '(1b) anulación con reposición de insumos: el saldo vuelve EXACTO al inicial (+6, no +12)',
    abs($afterVoidB - $initialB) < 0.001,
    "initial=$initialB afterSale=$afterSaleB afterVoid=$afterVoidB",
    $failures,
    $checks
);

setIngredientReversal($companyId, false);

// ═══════════════════════════════════════════════════════════════════════════
// (2) Movimiento backdated: SUM correcto y lectores de acuerdo
// ═══════════════════════════════════════════════════════════════════════════

$beforeBackdate = Inventory::onHand($stockItemId, $outletId);

// Compra cargada CON FECHA DE AYER, después de movimientos de hoy: es el caso
// que desincroniza el snapshot `stockOnHand` de las filas posteriores.
Inventory::manageStock([
    'itemId'        => $stockItemId,
    'outletId'      => $outletId,
    'date'          => date('Y-m-d H:i:s', strtotime('-1 day')),
    'locationId'    => null,
    'count'         => 17,
    'type'          => '+',
    'source'        => 'purchase',
    'note'          => 'stock_ledger_test: backdated',
    'userId'        => $userId,
    'companyId'     => $companyId,
    'transactionId' => null,
]);

$expected = $beforeBackdate + 17.0;
$sumOnHand = Inventory::onHand($stockItemId, $outletId);
check(
    '(2) onHand() (SUM con signo) suma el movimiento con fecha retroactiva',
    abs($sumOnHand - $expected) < 0.001,
    "before=$beforeBackdate expected=$expected onHand=$sumOnHand",
    $failures,
    $checks
);

// El desglose por depósito deriva del MISMO ledger: la suma de sus grupos
// tiene que dar el saldo, sin invariante que mantener a mano.
$byLoc = Inventory::onHandByLocation($stockItemId, $outletId);
check(
    '(2) onHandByLocation(): la suma de los depósitos es exactamente el saldo',
    abs(array_sum($byLoc) - $sumOnHand) < 0.001,
    'byLocation=' . json_encode($byLoc) . " onHand=$sumOnHand",
    $failures,
    $checks
);

// El reporte de niveles de stock (F1: migrado a SUM) tiene que coincidir con
// el lector único. Con el snapshot NO coincidía tras un backdated.
$reportRows = (new \Punto\Api\Reports\StockService())->levels($companyId, $outletId);
$reportRow  = null;
foreach ($reportRows as $r) {
    if ($r['itemId'] === $stockItemId) { $reportRow = $r; break; }
}
check(
    '(2) Reports\\StockService::levels() reporta el MISMO saldo que onHand()',
    $reportRow !== null && abs(((float) $reportRow['onHand']) - $sumOnHand) < 0.001,
    json_encode($reportRow) . " onHand=$sumOnHand",
    $failures,
    $checks
);
check(
    '(2) el reporte deriva principal + depósitos del ledger: su suma es el saldo',
    $reportRow !== null
        && abs(
            ((float) $reportRow['principal']['count'])
            + array_sum(array_map(static fn ($d) => (float) $d['count'], $reportRow['depots']))
            - $sumOnHand
        ) < 0.001,
    json_encode($reportRow ? ['principal' => $reportRow['principal'], 'depots' => $reportRow['depots']] : null),
    $failures,
    $checks
);

// ═══════════════════════════════════════════════════════════════════════════
// (3) Contrato de retorno de manageStock()
// ═══════════════════════════════════════════════════════════════════════════

// ── (3a) no-op legítimo: devuelve false, NO lanza ──────────────────────────
$noopThrew = false;
$noopResult = null;
try {
    $noopResult = Inventory::manageStock([
        'itemId'        => $noTrackId,
        'outletId'      => $outletId,
        'date'          => date('Y-m-d H:i:s'),
        'locationId'    => null,
        'count'         => 5,
        'type'          => '+',
        'source'        => 'adjustment',
        'note'          => 'stock_ledger_test: no-op',
        'userId'        => $userId,
        'companyId'     => $companyId,
        'transactionId' => null,
    ]);
} catch (\Throwable $e) {
    $noopThrew = true;
    $noopResult = get_class($e) . ': ' . $e->getMessage();
}
check(
    '(3a) ítem que no trackea inventario: manageStock() devuelve false y NO lanza',
    !$noopThrew && $noopResult === false,
    'threw=' . var_export($noopThrew, true) . ' result=' . var_export($noopResult, true),
    $failures,
    $checks
);

$noopRows = ncmExecute(
    "SELECT COUNT(*) AS n FROM stock WHERE itemid = ? AND stocknote = 'stock_ledger_test: no-op'",
    [$noTrackId]
);
check(
    '(3a) el no-op no deja fila en el ledger',
    $noopRows && (int) $noopRows['n'] === 0,
    json_encode($noopRows),
    $failures,
    $checks
);

// ── (3b) escritura que falla de verdad: LANZA ──────────────────────────────
// Se fuerza con un outletId inexistente: `stock.outletId` tiene FK NOT NULL a
// `outlet`, así que el INSERT viola la FK. Va al FINAL y FUERA de cualquier
// transacción abierta a propósito — el statement fallido no arrastra nada más.
$failThrew = false;
$failResult = null;
try {
    $failResult = Inventory::manageStock([
        'itemId'        => $stockItemId,
        'outletId'      => '00000000-0000-4000-8000-000000000000', // no existe en `outlet`
        'date'          => date('Y-m-d H:i:s'),
        'locationId'    => null,
        'count'         => 1,
        'type'          => '+',
        'source'        => 'adjustment',
        'note'          => 'stock_ledger_test: fk-violation',
        'userId'        => $userId,
        'companyId'     => $companyId,
        'transactionId' => null,
    ]);
} catch (\Throwable $e) {
    $failThrew = true;
    $failResult = get_class($e);
}
check(
    '(3b) INSERT del ledger que falla de verdad: manageStock() LANZA (no devuelve false)',
    $failThrew && $failResult !== false,
    'threw=' . var_export($failThrew, true) . ' result=' . var_export($failResult, true),
    $failures,
    $checks
);

harnessFinish($failures, $checks);
