<?php

declare(strict_types=1);

/**
 * verify_production_cogs.php — arnés chico que demuestra, sin mocks, el fix
 * de 2026-08-19 (context/modules/06-produccion.md §7 y
 * context/modules/05-stock.md regla 4): el costo de producción directa nunca
 * se calculaba porque `SaleService.php` comparaba `item.itemType ===
 * 'direct_production'`, un string que NUNCA es un valor persistido (es una
 * etiqueta sintética de `getItemTypeName()`, solo UI). El fix reemplaza esa
 * comparación por el predicado real (`Inventory::saleExplodesRecipe()`,
 * flags `itemProduction`/`itemTrackInventory`).
 *
 * Verifica, contra `SaleService::save()` REAL (mismo camino que
 * /api/v1/sales.php) y sin mocks:
 *
 *   1. `itemSold.itemSoldCOGS` de una venta de un ítem de producción
 *      directa (VERIFY-PROD-DIRECT, seed.sql) se calcula como el costo real
 *      de sus insumos (VERIFY-PROD-INSUMO), no queda null/0.
 *   2. El movimiento de stock que descuenta el insumo consumido lleva
 *      `stockSource = 'production'` (antes siempre 'sale', porque comparaba
 *      contra `$sD['type']`, campo que el POS nunca manda).
 *   3. `Reports\ProductionService::general()`/`detail()` (tabs
 *      "General"/"Detallado") ahora SÍ traen esa venta — antes filtraban
 *      `itemType = 'direct_production'`, el mismo string sintético, 0 filas
 *      siempre.
 *
 * Ampliado 2026-08-22 con la unificación del costeo en `RecipeCosting`
 * (reporte del tester "Actualización 21" #1 — la ficha del ítem y el reporte
 * de producción mostraban números distintos porque había TRES fórmulas). Los
 * casos 4-7 cubren, sobre una receta de dos niveles:
 *
 *   4. Una sub-preparación se costea con SUS insumos, no con su `itemCost`
 *      de catálogo (la fórmula de la venta era de un solo nivel), y un insumo
 *      sin ledger de stock cae a su costo de catálogo en vez de valer 0.
 *   5. La MISMA receta costeada contra otra sucursal da otro número — la
 *      sucursal es un parámetro, no `OUTLET_ID` (la de la sesión).
 *   6. El desglose expone de dónde salió cada costo (`avg` vs `catalog`), la
 *      profundidad de cada hoja y la merma planificada aplicada.
 *   7. La venta real registra EXACTAMENTE ese costo en `itemSoldCOGS` (y es
 *      unitario), descuenta el insumo del segundo nivel, y no inventa
 *      movimientos para el insumo sin control de stock.
 *
 * Requiere el mismo Postgres migrado+seedeado que `run_sale_chain.php` (ver
 * seed.sql, ítems VERIFY-PROD-INSUMO / VERIFY-PROD-DIRECT + su
 * item_compound). Uso: ver run.sh, invocarlo como paso adicional con las
 * mismas env vars POSTGRES_*.
 *
 * Exit code 0 solo si TODOS los casos pasan; 1 si falla cualquiera.
 *
 * Los casos 3/3b vivieron un tiempo como AVISO no-bloqueante porque no se
 * sabía por qué fallaban. Se re-endurecieron el 2026-08-22 al confirmar la
 * causa raíz contra Postgres real: `MAX()` sobre columnas uuid
 * (`function max(uuid) does not exist`) que `DB::Execute()` se tragaba
 * devolviendo `false`. Ya no hay razón para tolerarlos.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Reports\ProductionService as ProductionReportService;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;

$PY_COMPANY  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET   = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_REGISTER = '81c541da-640e-4891-a1a0-b32841e64c75';
$PY_USER     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$INSUMO_ID   = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6a'; // VERIFY-PROD-INSUMO
$DIRECT_ID   = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6b'; // VERIFY-PROD-DIRECT, receta: 2 x insumo
$RECIPE_QTY  = 2.0;
$INSUMO_COST = 500.0; // costo unitario que le vamos a dar al insumo vía manageStock()

// Reconstruye lo que `apiAuthTenant()` hace en el request real, sin JWT —
// mismo patrón que run_sale_chain.php / verify_realtime.php.
$companyId  = $PY_COMPANY;
$outletId   = $PY_OUTLET;
$userId     = $PY_USER;
$registerId = $PY_REGISTER;
$roleId     = '1';
require API_APP_DIR . '/data.php';

$failures = [];

// ── Setup: costo real del insumo vía manageStock() (código de producción,
//    no un INSERT crudo — así el promedio ponderado lo calcula el mismo
//    camino que usa producción/compras). Compra de 100 unidades a 500 c/u. ──
$setup = \Punto\App\Domain\Inventory::manageStock([
    'itemId'        => $INSUMO_ID,
    'source'        => 'purchase',
    'count'         => 100,
    'type'          => '+',
    'cogs'          => $INSUMO_COST,
    'userId'        => $userId,
    'transactionId' => null,
    'outletId'      => $outletId,
    'locationId'    => null,
    'note'          => 'verify_production_cogs setup',
    'date'          => date('Y-m-d H:i:s'),
    'companyId'     => $companyId,
]);
if ($setup === false) {
    fwrite(STDERR, "Setup: manageStock() del insumo devolvió false — revisar que VERIFY-PROD-INSUMO esté seedeado (itemtrackinventory=TRUE)\n");
    exit(1);
}

// ── Venta: 3 unidades de VERIFY-PROD-DIRECT. Costo esperado POR UNIDAD =
//    RECIPE_QTY * INSUMO_COST = 2 * 500 = 1000 (itemSoldCOGS es costo
//    UNITARIO, no de línea — los reportes lo multiplican por itemSoldUnits,
//    ver ProductsService.php:281/98). Stock del insumo consumido esperado =
//    RECIPE_QTY * unidades vendidas = 2 * 3 = 6. ──────────────────────────
$qtySold          = 3.0;
$unitPrice        = 9000.0;
$expectedUnitCOGS = $RECIPE_QTY * $INSUMO_COST;
$expectedInsumoConsumed = $RECIPE_QTY * $qtySold;

$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);

$uid = 'verify-production-cogs-' . bin2hex(random_bytes(6));
$payload = [
    'transaction' => [
        'uid'      => $uid,
        'type'     => 0, // Cashsale
        'sale'     => [[
            'itemId'        => $DIRECT_ID,
            'count'         => $qtySold,
            'name'          => 'VERIFY-PROD-DIRECT',
            'uniPrice'      => $unitPrice,
            'price'         => $unitPrice,
            'total'         => $unitPrice * $qtySold,
            'tax'           => 0,
            'discount'      => 0,
            'totalDiscount' => 0,
            'user'          => '',
            'type'          => '',
            'date'          => '',
            'note'          => '',
            'currency'      => '',
            'uId'           => 0,
        ]],
        'subtotal' => $unitPrice * $qtySold,
        'tax'      => 0,
        'discount' => 0,
        'payment'  => [
            ['type' => 'cash', 'name' => 'Efectivo', 'total' => $unitPrice * $qtySold],
        ],
        'date'      => date('Y-m-d H:i:s'),
        'timestamp' => time(),
    ],
];

try {
    $input  = SaleInput::fromPayload($payload, $companyId);
    $result = $service->save($input);
} catch (InvalidSaleInputException|SaleAbortedException|DuplicateSaleException $e) {
    fwrite(STDERR, '  FAIL  la venta no se pudo guardar: ' . $e->getMessage() . "\n");
    exit(1);
}

$transactionId = $result->transactionId;

// ── Caso 1: itemSoldCOGS ────────────────────────────────────────────────
$row = $db->Execute(
    'SELECT itemSoldCOGS FROM itemSold WHERE transactionId = ? AND itemId = ? LIMIT 1',
    [$transactionId, $DIRECT_ID]
);
if (!$row || $row->EOF) {
    $failures[] = 'Caso 1: no se encontró la fila itemSold de la venta recién guardada';
} else {
    $got = abs((float) ($row->fields['itemsoldcogs'] ?? 0));
    if (round($got, 6) !== round($expectedUnitCOGS, 6)) {
        $failures[] = "Caso 1: itemSoldCOGS esperado {$expectedUnitCOGS}, obtenido {$got} — revisar SaleService::persistItemsAndStock() (predicado \$isDirectProduction)";
    } else {
        echo "[verify_production_cogs] OK caso 1: itemSoldCOGS = {$got} (costo real del insumo, no null)\n";
    }
}

// ── Caso 2: stockSource del movimiento del insumo consumido ────────────
$row = $db->Execute(
    "SELECT stockSource, stockCount FROM stock
      WHERE itemId = ? AND transactionId = ? ORDER BY stockDate DESC LIMIT 1",
    [$INSUMO_ID, $transactionId]
);
if (!$row || $row->EOF) {
    $failures[] = 'Caso 2: no se encontró movimiento de stock del insumo para esta transacción';
} else {
    $source = (string) ($row->fields['stocksource'] ?? '');
    $count  = abs((float) ($row->fields['stockcount'] ?? 0));
    if ($source !== 'production') {
        $failures[] = "Caso 2: stockSource esperado 'production', obtenido '{$source}' — revisar \$source = \$isDirectProduction ? 'production' : 'sale' en SaleService.php";
    } elseif (round($count, 6) !== round($expectedInsumoConsumed, 6)) {
        $failures[] = "Caso 2: stockCount esperado {$expectedInsumoConsumed}, obtenido {$count}";
    } else {
        echo "[verify_production_cogs] OK caso 2: stock del insumo consumido con stockSource='production', count={$count}\n";
    }
}

// ── Casos 4-7: fórmula única de costeo de receta (RecipeCosting, 2026-08-22)
//
// Los tres agujeros que tenía `getProductionCOGS()` y que ninguna prueba
// cubría, con una receta de DOS niveles (seed.sql):
//
//     VERIFY-PROD-L2  (terminado, producción directa)
//       ├── 1 × VERIFY-PROD-SUBPREP  (producción directa, sin stock propio)
//       │        └── 3 × VERIFY-PROD-INSUMO  (trackeable, promedio real)
//       └── 1 × VERIFY-PROD-NOSTOCK  (sin ledger, merma 20%, itemCost 250)
//
//   (a) DOS NIVELES: el costo de SUBPREP tiene que ser el de sus insumos
//       (3 × 500 = 1500), no su `itemCost` de catálogo (7777, puesto en el
//       seed justamente para que un costeo de un nivel se delate).
//   (b) FALLBACK A CATÁLOGO: NOSTOCK no tiene ni puede tener filas en `stock`
//       (manageStock es no-op sin itemTrackInventory). La fórmula vieja lo
//       valuaba 0; vale su `itemCost`. Con merma 20%: 1 / (1 - 0.20) = 1.25
//       unidades → 1.25 × 250 = 312.5.
//   (c) SUCURSAL EXPLÍCITA: el mismo ítem en la sucursal B, donde el insumo
//       se compró a 800, cuesta distinto. La fórmula vieja caía en OUTLET_ID
//       (la de la SESIÓN) y devolvía el costo de la sucursal A siempre.
$OUTLET_B      = 'c7d3e9a4-2b15-4f68-9c0e-5a7b8d2e1f34';
$SUBPREP_ID    = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6c';
$NOSTOCK_ID    = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6d';
$L2_ID         = 'b4a1e5f2-6c3d-4e21-9a8b-1f2e3d4c5b6e';
$INSUMO_COST_B = 800.0;

// Costo esperado por unidad de L2, sucursal A:
//   SUBPREP: 1 × (3 × 500)             = 1500
//   NOSTOCK: conMerma(1, 20%) × 250    =  312.5
$expectedL2A = (3 * $INSUMO_COST) + (1 / (1 - 0.20)) * 250.0;
// Misma receta, sucursal B (insumo a 800): 3 × 800 + 312.5
$expectedL2B = (3 * $INSUMO_COST_B) + (1 / (1 - 0.20)) * 250.0;

// Sucursal B propia de este caso (no toca la del seed): se crea acá, como
// hace verify_outlet_visibility.php con la suya.
$db->Execute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName',
    [$OUTLET_B, 'Verify PY - Sucursal costeo B', $companyId]
);

// El MISMO insumo, comprado más caro en la sucursal B.
$setupB = \Punto\App\Domain\Inventory::manageStock([
    'itemId'        => $INSUMO_ID,
    'source'        => 'purchase',
    'count'         => 100,
    'type'          => '+',
    'cogs'          => $INSUMO_COST_B,
    'userId'        => $userId,
    'transactionId' => null,
    'outletId'      => $OUTLET_B,
    'locationId'    => null,
    'note'          => 'verify_production_cogs setup sucursal B',
    'date'          => date('Y-m-d H:i:s'),
    'companyId'     => $companyId,
]);
if ($setupB === false) {
    $failures[] = 'Setup sucursal B: manageStock() del insumo devolvió false';
}

// ── Caso 4: receta de dos niveles + insumo sin ledger, sucursal A ────────
$costA = \Punto\App\Domain\RecipeCosting::cost($L2_ID, $companyId, $outletId);
if (round((float) $costA['total'], 6) !== round($expectedL2A, 6)) {
    $failures[] = "Caso 4: RecipeCosting::cost(L2, sucursal A) esperado {$expectedL2A}, obtenido "
        . $costA['total'] . ' — si dio 7777+ la sub-preparación se valuó con su itemCost de catálogo'
        . ' en vez de explotarse; si dio 1500 el insumo sin ledger (VERIFY-PROD-NOSTOCK) se contó 0.';
} else {
    echo "[verify_production_cogs] OK caso 4: receta de 2 niveles + insumo sin ledger = {$costA['total']}\n";
}

// ── Caso 5: la MISMA receta en otra sucursal cuesta distinto ────────────
$costB = \Punto\App\Domain\RecipeCosting::cost($L2_ID, $companyId, $OUTLET_B);
if (round((float) $costB['total'], 6) !== round($expectedL2B, 6)) {
    $failures[] = "Caso 5: RecipeCosting::cost(L2, sucursal B) esperado {$expectedL2B}, obtenido "
        . $costB['total'] . ' — si dio lo mismo que el caso 4, la sucursal se está ignorando'
        . ' (la fórmula vieja caía en OUTLET_ID, la de la sesión).';
} else {
    echo "[verify_production_cogs] OK caso 5: la misma receta en la sucursal B = {$costB['total']} (insumo a {$INSUMO_COST_B})\n";
}

// ── Caso 6: el desglose dice de dónde salió cada costo ──────────────────
$byItem = [];
foreach ($costA['lines'] as $line) {
    $byItem[$line['itemId']] = $line;
}
$insumoLine  = $byItem[$INSUMO_ID]  ?? null;
$nostockLine = $byItem[$NOSTOCK_ID] ?? null;

if (!$insumoLine || !$nostockLine) {
    $failures[] = 'Caso 6: el desglose no trae las dos hojas esperadas (insumo trackeable + insumo sin ledger); trae: '
        . implode(', ', array_keys($byItem));
} elseif ($insumoLine['source'] !== \Punto\App\Domain\RecipeCosting::SOURCE_AVG) {
    $failures[] = "Caso 6: el insumo trackeable debería valuarse con el promedio del ledger ('avg'), no '{$insumoLine['source']}'";
} elseif ($nostockLine['source'] !== \Punto\App\Domain\RecipeCosting::SOURCE_CATALOG) {
    $failures[] = "Caso 6: el insumo sin ledger debería caer al costo de catálogo ('catalog'), no '{$nostockLine['source']}'";
} elseif ((int) $insumoLine['depth'] !== 2) {
    $failures[] = "Caso 6: el insumo cuelga de una sub-preparación, depth esperado 2, obtenido {$insumoLine['depth']}";
} elseif (round((float) $nostockLine['qty'], 6) !== round(1 / (1 - 0.20), 6)) {
    $failures[] = "Caso 6: la merma planificada del 20% no se aplicó — qty esperada 1.25, obtenida {$nostockLine['qty']}";
} else {
    echo "[verify_production_cogs] OK caso 6: desglose correcto (insumo avg depth=2, sin-ledger catalog qty=1.25 con merma)\n";
}

// ── Caso 7: la venta real registra ESE número y descuenta ESE stock ─────
$qtyL2 = 2.0;
$uidL2 = 'verify-production-cogs-l2-' . bin2hex(random_bytes(6));
$payloadL2 = [
    'transaction' => [
        'uid'  => $uidL2,
        'type' => 0,
        'sale' => [[
            'itemId'        => $L2_ID,
            'count'         => $qtyL2,
            'name'          => 'VERIFY-PROD-L2',
            'uniPrice'      => 20000.0,
            'price'         => 20000.0,
            'total'         => 20000.0 * $qtyL2,
            'tax'           => 0,
            'discount'      => 0,
            'totalDiscount' => 0,
            'user'          => '',
            'type'          => '',
            'date'          => '',
            'note'          => '',
            'currency'      => '',
            'uId'           => 0,
        ]],
        'subtotal' => 20000.0 * $qtyL2,
        'tax'      => 0,
        'discount' => 0,
        'payment'  => [['type' => 'cash', 'name' => 'Efectivo', 'total' => 20000.0 * $qtyL2]],
        'date'      => date('Y-m-d H:i:s'),
        'timestamp' => time(),
    ],
];

try {
    $resultL2 = $service->save(SaleInput::fromPayload($payloadL2, $companyId));
} catch (InvalidSaleInputException|SaleAbortedException|DuplicateSaleException $e) {
    $resultL2 = null;
    $failures[] = 'Caso 7: la venta de L2 no se pudo guardar: ' . $e->getMessage();
}

if ($resultL2 !== null) {
    $txL2 = $resultL2->transactionId;

    $row = $db->Execute(
        'SELECT itemSoldCOGS FROM itemSold WHERE transactionId = ? AND itemId = ? LIMIT 1',
        [$txL2, $L2_ID]
    );
    if (!$row || $row->EOF) {
        $failures[] = 'Caso 7: no se encontró la fila itemSold de la venta de L2';
    } else {
        $gotL2 = abs((float) ($row->fields['itemsoldcogs'] ?? 0));
        if (round($gotL2, 6) !== round($expectedL2A, 6)) {
            $failures[] = "Caso 7: itemSoldCOGS de L2 esperado {$expectedL2A} (UNITARIO, no × {$qtyL2}), obtenido {$gotL2}";
        } else {
            echo "[verify_production_cogs] OK caso 7: la venta registró el mismo costo que RecipeCosting ({$gotL2}, unitario)\n";
        }
    }

    // El insumo del nivel 2 SÍ se descuenta: 3 por unidad de L2 × 2 vendidas.
    $row = $db->Execute(
        'SELECT SUM(ABS(stockCount)) AS moved FROM stock WHERE itemId = ? AND transactionId = ?',
        [$INSUMO_ID, $txL2]
    );
    $moved = ($row && !$row->EOF) ? (float) ($row->fields['moved'] ?? 0) : 0.0;
    if (round($moved, 6) !== round(3 * $qtyL2, 6)) {
        $failures[] = 'Caso 7: el insumo del segundo nivel debía descontarse ' . (3 * $qtyL2) . ", se descontó {$moved}";
    } else {
        echo "[verify_production_cogs] OK caso 7b: el insumo del segundo nivel se descontó ({$moved})\n";
    }

    // El insumo sin ledger NO genera movimiento — cuesta, pero no se descuenta.
    $row = $db->Execute(
        'SELECT COUNT(*) AS n FROM stock WHERE itemId = ? AND transactionId = ?',
        [$NOSTOCK_ID, $txL2]
    );
    $n = ($row && !$row->EOF) ? (int) ($row->fields['n'] ?? 0) : 0;
    if ($n !== 0) {
        $failures[] = "Caso 7c: el insumo sin control de stock no debe generar movimiento, se generaron {$n}";
    } else {
        echo "[verify_production_cogs] OK caso 7c: el insumo sin ledger costea pero no mueve stock\n";
    }
}

// ── Caso 3: Reports\ProductionService::general()/detail() traen la venta ──
//
// RESUELTO 2026-08-22. Este caso vivió un tiempo como AVISO no-bloqueante
// porque la investigación estática no encontraba por qué fallaba. La causa
// era la hipótesis (b) que ese comentario dejó anotada: la query agregada de
// `general()` tiraba un error REAL de Postgres —
// `SQLSTATE[42883]: function max(uuid) does not exist`, por `MAX(a.userId)` y
// `MAX(b.outletId)` sobre columnas uuid — y `DB::Execute()` se lo tragaba
// devolviendo `false` (solo `error_log`), así que el tab salía vacío sin
// ningún síntoma. Corregido en `Reports/ProductionService.php` con
// `(array_agg(x ORDER BY itemSoldDate DESC NULLS LAST))[1]`.
//
// Con la causa raíz confirmada y arreglada, el caso vuelve a ser un assert
// duro: si no trae la venta, el arnés falla. El diagnóstico (ErrorMsg + flags
// reales del ítem) se conserva en el mensaje de la falla, que es cuando sirve.
$today  = date('Y-m-d');
$report = new ProductionReportService();

/**
 * Corre un caso de ProductionService (general()/detail()) y exige que traiga
 * la venta. Si no la trae, arma el diagnóstico y lo suma a $failures.
 */
function verifyPgProdCase(string $label, callable $call, string $directId, string $companyId, array &$failures): void
{
    global $db;
    // $db->firstError (lo que expone ErrorMsg()) es STICKY entre llamadas:
    // solo se resetea en StartTrans() al pasar de profundidad 0 a 1
    // (DB.php:557-560) — nunca en un Execute() exitoso NI en uno fallido
    // subsiguiente (noteFirstError() es no-op si firstError ya está seteado).
    // Sin esto, si caso 3 (general()) tira un error real, caso 3b (detail())
    // heredaría ese mismo firstError viejo y su propio error (si lo tuviera)
    // quedaría enmascarado. Envolver la llamada en Start/CompleteTrans
    // (inocuo sobre SELECTs puros) resetea firstError ANTES de cada caso.
    // Este mecanismo es el que destapó el `max(uuid)` — no sacarlo.
    $db->StartTrans();
    $errBefore = $db->ErrorMsg();
    $result    = $call();
    $errAfter  = $db->ErrorMsg();
    $db->CompleteTrans();

    foreach (($result['rows'] ?? []) as $r) {
        if (($r['itemId'] ?? null) === $directId) {
            echo "[verify_production_cogs] OK {$label}: trae la venta\n";
            return;
        }
    }

    $diag = "itemId={$directId} companyId={$companyId}";
    if ($errAfter !== '' && $errAfter !== $errBefore) {
        // Un error de Postgres que ncmExecute() se tragó — ESA es la causa
        // raíz, no el predicado. Así se encontró el max(uuid).
        $diag .= " | \$db->ErrorMsg() (nuevo tras esta llamada): {$errAfter}";
    }
    $liveItem     = $db->GetRow('SELECT itemtype, itemcansale, itemtrackinventory, itemproduction FROM item WHERE itemId = ?', [$directId]);
    $liveCompound = $db->GetRow('SELECT 1 AS x FROM item_compound WHERE parentItemId = ?', [$directId]);
    $diag .= ' | item real: ' . ($liveItem ? json_encode($liveItem) : 'NO ENCONTRADO');
    $diag .= ' | item_compound existe: ' . ($liveCompound ? 'sí' : 'NO');

    $failures[] = "{$label}: no trajo VERIFY-PROD-DIRECT — {$diag}";
}

// buildRows() devuelve {rows:[...], totals:{...}} — cada row usa la key
// `itemId` (no `id`), ver ProductionService.php:259-300.
verifyPgProdCase(
    'caso 3 (Reports\\ProductionService::general())',
    fn() => $report->general($today . ' 00:00:00', $today . ' 23:59:59', '', $companyId),
    $DIRECT_ID,
    $companyId,
    $failures
);
verifyPgProdCase(
    'caso 3b (Reports\\ProductionService::detail())',
    fn() => $report->detail($today . ' 00:00:00', $today . ' 23:59:59', '', $companyId),
    $DIRECT_ID,
    $companyId,
    $failures
);

if ($failures !== []) {
    fwrite(STDERR, "[verify_production_cogs] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_production_cogs] TODO OK\n";
exit(0);
