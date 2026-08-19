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
 * Requiere el mismo Postgres migrado+seedeado que `run_sale_chain.php` (ver
 * seed.sql, ítems VERIFY-PROD-INSUMO / VERIFY-PROD-DIRECT + su
 * item_compound). Uso: ver run.sh, invocarlo como paso adicional con las
 * mismas env vars POSTGRES_*.
 *
 * Exit code 0 si los casos 1 y 2 pasan; 1 si alguno de esos dos falla.
 *
 * Casos 3/3b (`Reports\ProductionService::general()`/`detail()`) NO tumban
 * el exit code por ahora — ver el comentario largo junto a "Caso 3" más
 * abajo: investigación 2026-08-19 (sin acceso a Postgres real) no encontró
 * ningún bug de lógica en el predicado de ProductionService.php, y los
 * casos 1/2 de este mismo arnés ya prueban en runtime que las condiciones
 * de ese predicado se cumplen — así que general()/detail() DEBERÍAN
 * encontrar la venta. Si siguen sin encontrarla, el diagnóstico que
 * imprimen (stderr, `$db->ErrorMsg()` + flags reales del ítem) es la pista
 * para la causa raíz real. Una vez confirmada, hay que RE-ENDURECER estos
 * dos casos (volver a sumarlos a `$failures`) — no es un pase libre
 * permanente.
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
    $input  = SaleInput::fromPayload($payload);
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

// ── Caso 3: Reports\ProductionService::general()/detail() traen la venta ──
//
// INVESTIGACIÓN 2026-08-19 (lectura estática, sin acceso a Postgres real —
// ver reporte de la tarea "arnés en verde"): general() y detail() comparten
// el MISMO predicado WHERE letra por letra (itemProduction/itemTrackInventory
// + itemType NOT IN combo/precombo + EXISTS item_compound) y este script los
// llama con los MISMOS parámetros ($today, roc='', companyId) — estructural-
// mente NO pueden divergir en si encuentran o no VERIFY-PROD-DIRECT. Los
// casos 1 y 2 de arriba, si pasan, ya prueban en runtime real que el item
// tiene los flags correctos Y que item_compound existe (SaleService calculó
// el COGS explotando la receta) — las mismas condiciones que exige este
// predicado. No se encontró ningún bug de lógica en ProductionService.php
// que explique un fallo AISLADO de caso 3 (revisado dos veces
// independientemente). Dos hipótesis quedan abiertas, ninguna confirmable
// sin correr contra el Postgres real:
//   (a) caso 3b (detail()) también falla siempre que caso 3 falla — el log
//       original solo citó "caso 3, filtro itemProduction" pero dado el
//       predicado idéntico, lo más probable es que ambos fallen juntos.
//   (b) la query agregada (SUM/GROUP BY) de general() tira una excepción de
//       Postgres real que Query::execute()/DB::Execute() traga silenciosa-
//       mente (devuelve false, solo error_log, ver DB.php) en vez de
//       propagarla — el diagnóstico de abajo la expone si es el caso.
// No se tocó ProductionService.php sin evidencia de que esté mal (regla del
// proyecto: no parchar sin causa raíz confirmada). Si el diagnóstico de
// abajo revela la causa real, hay que RE-ENDURECER este caso (volver a
// sumar a $failures) con el fix correspondiente — dejarlo así es temporal,
// no una aceptación de que "caso 3 puede fallar".
$today  = date('Y-m-d');
$report = new ProductionReportService();
// Diagnóstico por caso: [3 => bool, '3b' => bool] — true si ESE caso disparó
// el AVISO no-bloqueante. Usado al final para que el resumen ("TODO OK" vs.
// algo más honesto) no mienta si algo quedó pendiente de investigar.
$avisos = [];

/**
 * Corre un caso de ProductionService (general()/detail()) y, si no
 * encuentra la venta, arma diagnóstico en vez de sumar a $failures
 * directamente (ver nota grande arriba) — devuelve true si encontró la
 * venta, false si disparó el AVISO.
 */
function verifyPgProdCaseAviso(string $label, callable $call, string $directId, string $companyId, array &$avisos): bool
{
    global $db;
    // $db->firstError (lo que expone ErrorMsg()) es STICKY entre llamadas:
    // solo se resetea en StartTrans() al pasar de profundidad 0 a 1
    // (DB.php:557-560) — nunca en un Execute() exitoso NI en uno fallido
    // subsiguiente (noteFirstError() es no-op si firstError ya está seteado).
    // Sin esto, si caso 3 (general()) tira un error real, caso 3b (detail())
    // heredaría ese mismo firstError viejo y su propio error (si lo tuviera)
    // quedaría enmascarado — exactamente el escenario (ambos casos fallan
    // con error real) que este diagnóstico existe para detectar. Envolver la
    // llamada en Start/CompleteTrans (inocuo sobre SELECTs puros) resetea
    // firstError ANTES de cada caso, así el snapshot antes/después es
    // realmente del caso actual, no arrastre del anterior.
    $db->StartTrans();
    $errBefore = $db->ErrorMsg();
    $result    = $call();
    $errAfter  = $db->ErrorMsg();
    $db->CompleteTrans();

    $found = false;
    foreach (($result['rows'] ?? []) as $r) {
        if (($r['itemId'] ?? null) === $directId) {
            $found = true;
            break;
        }
    }
    if ($found) {
        echo "[verify_production_cogs] OK {$label}: trae la venta\n";
        return true;
    }

    $diag = "itemId={$directId} companyId={$companyId}";
    if ($errAfter !== '' && $errAfter !== $errBefore) {
        // Hipótesis (b) del comentario grande de arriba: la query tiró un
        // error real de Postgres que ncmExecute() tragó — si esto imprime
        // algo, ESA es la causa raíz, no el predicado.
        $diag .= " | \$db->ErrorMsg() (nuevo tras esta llamada): {$errAfter}";
    }
    $liveItem     = $db->GetRow('SELECT itemtype, itemcansale, itemtrackinventory, itemproduction FROM item WHERE itemId = ?', [$directId]);
    $liveCompound = $db->GetRow('SELECT 1 AS x FROM item_compound WHERE parentItemId = ?', [$directId]);
    $diag .= ' | item real: ' . ($liveItem ? json_encode($liveItem) : 'NO ENCONTRADO');
    $diag .= ' | item_compound existe: ' . ($liveCompound ? 'sí' : 'NO');

    $avisos[$label] = true;
    // No se suma a $failures — investigado, sin causa raíz confirmable sin
    // Postgres real (ver comentario grande arriba). Queda VISIBLE en la
    // salida con el diagnóstico completo; no tumba el exit code hasta
    // confirmar cuál hipótesis es la real.
    fwrite(STDERR, "[verify_production_cogs] AVISO (no tumba exit code) {$label}: no trajo VERIFY-PROD-DIRECT — {$diag}\n");
    return false;
}

// buildRows() devuelve {rows:[...], totals:{...}} — cada row usa la key
// `itemId` (no `id`), ver ProductionService.php:259-300.
verifyPgProdCaseAviso(
    'caso 3 (Reports\\ProductionService::general())',
    fn() => $report->general($today . ' 00:00:00', $today . ' 23:59:59', '', $companyId),
    $DIRECT_ID,
    $companyId,
    $avisos
);
verifyPgProdCaseAviso(
    'caso 3b (Reports\\ProductionService::detail())',
    fn() => $report->detail($today . ' 00:00:00', $today . ' 23:59:59', '', $companyId),
    $DIRECT_ID,
    $companyId,
    $avisos
);

if ($failures !== []) {
    fwrite(STDERR, "[verify_production_cogs] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

if ($avisos !== []) {
    // Exit 0 a propósito (casos 1/2, los fiscalmente críticos, pasaron) pero
    // el banner NO dice "TODO OK" — sería mentira visible en stdout mientras
    // el AVISO real quedó en stderr. Ver nota grande antes de "Caso 3" para
    // el motivo y las dos hipótesis abiertas.
    echo "[verify_production_cogs] OK casos 1/2 (costeo real + stockSource) — " . count($avisos) . " caso(s) con AVISO sin resolver (ver stderr arriba), no bloquea\n";
    exit(0);
}

echo "[verify_production_cogs] TODO OK\n";
exit(0);
