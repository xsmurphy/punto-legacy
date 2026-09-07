<?php

declare(strict_types=1);

/**
 * verify_addon_stock.php — la mitad de BACK del add-on: que una venta con
 * `selections` genere la línea hija, MUEVA EL LEDGER DE STOCK de la opción y
 * deje el detalle listo para que el ticket la imprima indentada.
 *
 * Por qué existe (context/41, fix del 2026-08-25): el add-on no descontaba
 * stock al cobrar una mesa. La causa estaba en el front —el carrito se
 * reconstruía sin `selections`, así que `expandAddonSelections` nunca corría—
 * pero al ir a verificarlo apareció que la otra mitad de la cadena tampoco
 * tenía arnés: NINGÚN test tocaba add-ons en una venta real. Un fix cuyo
 * efecto no se puede demostrar no está terminado, así que se cierra el hueco
 * de los dos lados. La mitad de FRONT (orden → `selections`, con la qty
 * volviendo a ser por unidad del padre) vive en
 * `frontend/lib/cart/__tests__/addon-rebuild-paths.test.ts`.
 *
 * Verifica contra `SaleService::save()` REAL (mismo camino que
 * /api/v1/sales.php), sin mocks:
 *
 *   1. La venta persiste DOS filas de `itemSold`: el padre y una hija por
 *      opción, con `itemSoldParent` apuntando al ítem padre.
 *   2. **El stock de la opción se descuenta**, con la cantidad multiplicada
 *      por las unidades del padre (2 hamburguesas con queso = 2 quesos). Es
 *      la regresión del bug: antes no había movimiento ninguno.
 *   3. La plata no se cuenta dos veces: el recargo se le RESTA al padre y se
 *      le da a la hija, así que padre + hija = exactamente lo que cobró la
 *      caja. `transactionTotal` sigue siendo el subtotal informado.
 *   4. `meta.transactionDetails` trae la hija con `type='addon'` — la ÚNICA
 *      señal con la que el ticket la indenta (`blocks.ts`, `itemNameCell()`
 *      keyea sobre `i.type === 'addon'`).
 *   5. Una opción repetida (qty=2 en la selección) descuenta optQty ×
 *      unidades del padre, no optQty ni unidades sueltas.
 *   6. El precio NUNCA viaja del cliente: mandar un `priceDelta` inflado en
 *      el payload no cambia lo que se cobra ni lo que se descuenta — el
 *      recargo sale de `addon_group_option`.
 *
 * Casos 7-11 — GRUPO POR CANTIDAD (mig 203, "caja surtida de 100 empanadas":
 * `qtyMode='quantity'`, el tope va sobre la SUMA del grupo y cada opción puede
 * ir sin tope propio, `maxQty NULL`):
 *
 *   7. La suma DENTRO del rango pasa, el precio total es Σ(priceDelta × qty)
 *      —no "delta × opciones elegidas"— y cada opción descuenta su propio qty
 *      × unidades del padre.
 *   8. Pasarse del máximo del grupo se RECHAZA, aunque ninguna opción tenga
 *      tope propio. Es la razón de ser del modo: sin el tope de suma, dos
 *      sabores sin `maxQty` arman una caja de cualquier tamaño.
 *   9. Quedarse por debajo del mínimo se RECHAZA. Una caja de 6 con 5 adentro
 *      no es una caja de 6.
 *  10. La MISMA regla corre en la ORDEN, no solo en la venta: `OrderCoreService
 *      ::create()` valida contra el mismo `AddonService::validateSelections`.
 *      Se prueba el camino feliz Y el rechazo — que la orden acepte lo que la
 *      venta rechaza dejaría la comanda pidiendo una caja imposible de cobrar.
 *  11. La orden persiste la hija con `childQty = optQty × qty del padre`, que
 *      es el contrato que `rebuildSelectionsFromOrder` invierte al cobrar.
 *
 * Requiere el mismo Postgres migrado+seedeado que `run_sale_chain.php` (ver
 * seed.sql, fixtures VERIFY-ADDON-STOCK-PARENT / VERIFY-ADDON-STOCK-OPT).
 * Uso: ver run.sh. Exit 0 solo si TODOS los casos pasan.
 */

require_once dirname(__DIR__, 3) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Orders\OrderCoreService;

$PY_COMPANY  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$PY_OUTLET   = '1a282724-6073-49c3-8bc3-0114a132e349';
$PY_REGISTER = '81c541da-640e-4891-a1a0-b32841e64c75';
$PY_USER     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

$PARENT_ID   = 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f601'; // VERIFY-ADDON-STOCK-PARENT
$OPT_ITEM_ID = 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f602'; // VERIFY-ADDON-STOCK-OPT (trackeable)
$OPTION_ID   = 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f604';

// Caja surtida (mig 203): grupo qtyMode='quantity' con min=max=6 y dos
// sabores sin tope propio (maxQty NULL). Ver seed.sql.
$BOX_ID      = 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f611'; // VERIFY-ADDON-BOX
$FLAVOR_A_ID = 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f612'; // recarga 500
$FLAVOR_B_ID = 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f613'; // no recarga
$OPT_A_ID    = 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f615';
$OPT_B_ID    = 'c1a2b3c4-d5e6-4f70-8a91-b2c3d4e5f616';
$BOX_PRICE   = 60000.0;
$FLAVOR_A_DELTA = 500.0;
$BOX_TOTAL   = 6; // minSelect = maxSelect del grupo
$BASE_PRICE  = 15000.0;
$PRICE_DELTA = 2000.0; // el de la BD — el único que vale

// Reconstruye lo que `apiAuthTenant()` hace en el request real, sin JWT —
// mismo patrón que run_sale_chain.php / verify_production_cogs.php.
$companyId  = $PY_COMPANY;
$outletId   = $PY_OUTLET;
$userId     = $PY_USER;
$registerId = $PY_REGISTER;
$roleId     = '1';
require API_APP_DIR . '/data.php';

$failures = [];

/** Saldo del ledger de stock de un ítem en la sucursal — la fuente de verdad. */
function stockBalance(string $itemId, string $outletId, string $companyId): float
{
    global $db;
    $rs = $db->Execute(
        'SELECT COALESCE(SUM(stockCount), 0) AS bal FROM stock
          WHERE itemId = ? AND outletId = ? AND companyId = ?',
        [$itemId, $outletId, $companyId]
    );
    if (!$rs || $rs->EOF) {
        return 0.0;
    }
    return (float) ($rs->fields['bal'] ?? 0);
}

/**
 * Vende `$parentUnits` del padre con la opción elegida `$optQty` veces.
 * El payload es EXACTAMENTE el que arma el POS: el precio de la línea ya
 * lleva el recargo adentro (`CartLine.unitPrice = base + Σ deltas`), y las
 * selecciones solo aportan optionId + qty.
 *
 * @return array{transactionId:string, subtotal:float}
 */
function sellWithAddon(
    SaleService $service,
    string $parentId,
    float $parentUnits,
    string $optionId,
    int $optQty,
    float $unitPrice,
    ?float $fakeDelta = null
): array {
    // Mismo patrón que stockBalance() más arriba: el tenant del script vive en
    // el scope global. `SaleInput::fromPayload()` lo necesita para bajar el
    // `timestamp` de emisión al reloj del comercio.
    global $companyId;

    $selection = ['optionId' => $optionId, 'qty' => $optQty];
    if ($fakeDelta !== null) {
        // Caso 6: un cliente malicioso/desactualizado mandando precio.
        $selection['priceDelta'] = $fakeDelta;
    }

    $subtotal = $unitPrice * $parentUnits;
    $payload  = [
        'transaction' => [
            'uid'  => 'verify-addon-stock-' . bin2hex(random_bytes(6)),
            'type' => 0, // Cashsale
            'sale' => [[
                'itemId'        => $parentId,
                'count'         => $parentUnits,
                'name'          => 'VERIFY-ADDON-STOCK-PARENT',
                'uniPrice'      => $unitPrice,
                'price'         => $unitPrice,
                'total'         => $subtotal,
                'tax'           => 0,
                'discount'      => 0,
                'totalDiscount' => 0,
                'user'          => '',
                'type'          => '',
                'date'          => '',
                'note'          => '',
                'currency'      => '',
                'uId'           => 0,
                'selections'    => [$selection],
            ]],
            'subtotal'  => $subtotal,
            'tax'       => 0,
            'discount'  => 0,
            'payment'   => [['type' => 'cash', 'name' => 'Efectivo', 'total' => $subtotal]],
            'date'      => date('Y-m-d H:i:s'),
            'timestamp' => time(),
        ],
    ];

    $result = $service->save(SaleInput::fromPayload($payload, $companyId));

    return ['transactionId' => $result->transactionId, 'subtotal' => $subtotal];
}

/**
 * Vende `$units` cajas surtidas con las selecciones dadas (grupo por
 * CANTIDAD). Mismo payload que arma el POS: el precio de la línea ya lleva el
 * recargo adentro y las selecciones solo aportan optionId + qty.
 *
 * @param array<int,array{optionId:string,qty:int}> $selections
 * @return array{transactionId:string, subtotal:float}
 */
function sellBox(
    SaleService $service,
    string $boxId,
    float $units,
    array $selections,
    float $unitPrice
): array {
    global $companyId;

    $subtotal = $unitPrice * $units;
    $payload  = [
        'transaction' => [
            'uid'  => 'verify-addon-box-' . bin2hex(random_bytes(6)),
            'type' => 0,
            'sale' => [[
                'itemId'        => $boxId,
                'count'         => $units,
                'name'          => 'VERIFY-ADDON-BOX',
                'uniPrice'      => $unitPrice,
                'price'         => $unitPrice,
                'total'         => $subtotal,
                'tax'           => 0,
                'discount'      => 0,
                'totalDiscount' => 0,
                'user'          => '',
                'type'          => '',
                'date'          => '',
                'note'          => '',
                'currency'      => '',
                'uId'           => 0,
                'selections'    => $selections,
            ]],
            'subtotal'  => $subtotal,
            'tax'       => 0,
            'discount'  => 0,
            'payment'   => [['type' => 'cash', 'name' => 'Efectivo', 'total' => $subtotal]],
            'date'      => date('Y-m-d H:i:s'),
            'timestamp' => time(),
        ],
    ];

    $result = $service->save(SaleInput::fromPayload($payload, $companyId));

    return ['transactionId' => $result->transactionId, 'subtotal' => $subtotal];
}

$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);

// ── Setup: stock inicial de la opción por el camino de producción ──────────
$setup = \Punto\App\Domain\Inventory::manageStock([
    'itemId'        => $OPT_ITEM_ID,
    'source'        => 'purchase',
    'count'         => 100,
    'type'          => '+',
    'cogs'          => 800,
    'userId'        => $userId,
    'transactionId' => null,
    'outletId'      => $outletId,
    'locationId'    => null,
    'note'          => 'verify_addon_stock setup',
    'date'          => date('Y-m-d H:i:s'),
    'companyId'     => $companyId,
]);
if ($setup === false) {
    fwrite(STDERR, "Setup: manageStock() de la opción devolvió false — revisar que VERIFY-ADDON-STOCK-OPT esté seedeado (itemtrackinventory=TRUE)\n");
    exit(1);
}

// ── Venta A: 2 unidades del padre, la opción elegida UNA vez ───────────────
$parentUnits = 2.0;
$optQty      = 1;
$unitPrice   = $BASE_PRICE + $PRICE_DELTA * $optQty;

$balanceBefore = stockBalance($OPT_ITEM_ID, $outletId, $companyId);

try {
    $saleA = sellWithAddon($service, $PARENT_ID, $parentUnits, $OPTION_ID, $optQty, $unitPrice);
} catch (InvalidSaleInputException|SaleAbortedException|DuplicateSaleException $e) {
    fwrite(STDERR, '  FAIL  la venta con add-on no se pudo guardar: ' . $e->getMessage() . "\n");
    exit(1);
}
$transA = $saleA['transactionId'];

// ── Caso 1: padre + hija en itemSold ──────────────────────────────────────
$rs = $db->Execute(
    'SELECT itemId, itemSoldUnits, itemSoldTotal, itemSoldParent FROM itemSold
      WHERE transactionId = ? ORDER BY itemSoldParent NULLS FIRST',
    [$transA]
);
$sold = [];
if ($rs !== false) {
    foreach ($rs->GetRows() as $row) {
        $sold[(string) $row['itemid']] = $row;
    }
}

if (count($sold) !== 2) {
    $failures[] = 'Caso 1: se esperaban 2 filas de itemSold (padre + hija de add-on), hay ' . count($sold)
        . ' — si hay 1, expandAddonSelections no corrió: la línea llegó sin `selections`, que es EXACTAMENTE el bug del cobro de mesa';
} elseif (!isset($sold[$OPT_ITEM_ID])) {
    $failures[] = 'Caso 1: no hay fila de itemSold para el ítem de la opción';
} elseif ((string) ($sold[$OPT_ITEM_ID]['itemsoldparent'] ?? '') !== $PARENT_ID) {
    $failures[] = 'Caso 1: itemSoldParent de la hija esperado ' . $PARENT_ID
        . ', obtenido ' . (string) ($sold[$OPT_ITEM_ID]['itemsoldparent'] ?? 'null');
} else {
    echo "[verify_addon_stock] OK caso 1: la venta persiste padre + hija de add-on, con itemSoldParent al ítem padre\n";
}

// ── Caso 2: EL STOCK DE LA OPCIÓN SE DESCUENTA (la regresión) ─────────────
$expectedConsumed = $optQty * $parentUnits;
$balanceAfter     = stockBalance($OPT_ITEM_ID, $outletId, $companyId);
$consumed         = $balanceBefore - $balanceAfter;

if (round($consumed, 6) !== round($expectedConsumed, 6)) {
    $failures[] = "Caso 2: el stock de la opción tenía que bajar {$expectedConsumed} y bajó {$consumed}"
        . ' — 0 significa que el add-on se está REGALANDO del inventario (el bug original)';
} else {
    $rsMov = $db->Execute(
        'SELECT stockSource, stockCount FROM stock WHERE itemId = ? AND transactionId = ? LIMIT 1',
        [$OPT_ITEM_ID, $transA]
    );
    if (!$rsMov || $rsMov->EOF) {
        $failures[] = 'Caso 2: el saldo bajó pero no hay movimiento atado a esta transacción — el descuento no es trazable';
    } else {
        $source = (string) ($rsMov->fields['stocksource'] ?? '');
        if ($source !== 'sale') {
            $failures[] = "Caso 2: stockSource del add-on esperado 'sale', obtenido '{$source}'";
        } else {
            echo "[verify_addon_stock] OK caso 2: el add-on descontó {$consumed} unidades de stock (stockSource='sale', atado a la transacción)\n";
        }
    }
}

// ── Caso 3: la plata no se duplica ────────────────────────────────────────
$parentTotal = (float) ($sold[$PARENT_ID]['itemsoldtotal'] ?? 0);
$childTotal  = (float) ($sold[$OPT_ITEM_ID]['itemsoldtotal'] ?? 0);
$detailSum   = $parentTotal + $childTotal;

$rsTx = $db->Execute('SELECT transactionTotal, meta FROM transaction WHERE transactionId = ?', [$transA]);
$txTotal = $rsTx && !$rsTx->EOF ? (float) ($rsTx->fields['transactiontotal'] ?? 0) : -1.0;

if (round($detailSum, 2) !== round($saleA['subtotal'], 2)) {
    $failures[] = "Caso 3: padre ({$parentTotal}) + hija ({$childTotal}) = {$detailSum}, pero la caja cobró {$saleA['subtotal']}"
        . ' — el recargo quedó contado dos veces o ninguna';
} elseif (round($childTotal, 2) !== round($PRICE_DELTA * $parentUnits, 2)) {
    $failures[] = 'Caso 3: la hija tenía que llevar solo su recargo (' . ($PRICE_DELTA * $parentUnits) . "), lleva {$childTotal}";
} elseif (round($txTotal, 2) !== round($saleA['subtotal'], 2)) {
    $failures[] = "Caso 3: transactionTotal esperado {$saleA['subtotal']}, obtenido {$txTotal}";
} else {
    echo "[verify_addon_stock] OK caso 3: padre + hija = {$detailSum} = lo que cobró la caja (el recargo se reparte, no se duplica)\n";
}

// ── Caso 4: el ticket puede indentar la hija ──────────────────────────────
$meta    = $rsTx && !$rsTx->EOF ? json_decode((string) $rsTx->fields['meta'], true) : [];
$details = json_decode((string) ($meta['transactionDetails'] ?? '[]'), true);
$childDetail = null;
foreach (is_array($details) ? $details : [] as $line) {
    if ((string) ($line['itemId'] ?? '') === $OPT_ITEM_ID) {
        $childDetail = $line;
        break;
    }
}

if ($childDetail === null) {
    $failures[] = 'Caso 4: la hija no aparece en meta.transactionDetails — el ticket no la imprime';
} elseif ((string) ($childDetail['type'] ?? '') !== 'addon') {
    $failures[] = "Caso 4: la hija del detalle tiene type='" . (string) ($childDetail['type'] ?? '')
        . "', el ticket la indenta solo con 'addon' (blocks.ts itemNameCell)";
} elseif ((string) ($childDetail['parent'] ?? '') !== $PARENT_ID) {
    $failures[] = 'Caso 4: la hija del detalle no referencia al ítem padre';
} else {
    echo "[verify_addon_stock] OK caso 4: la hija va en el detalle con type='addon' — el ticket la imprime indentada bajo su padre\n";
}

// ── Caso 5: opción repetida — optQty × unidades del padre ─────────────────
$parentUnitsB = 3.0;
$optQtyB      = 2;
$unitPriceB   = $BASE_PRICE + $PRICE_DELTA * $optQtyB;
$expectedB    = $optQtyB * $parentUnitsB; // 6

$balanceBeforeB = stockBalance($OPT_ITEM_ID, $outletId, $companyId);
try {
    $saleB = sellWithAddon($service, $PARENT_ID, $parentUnitsB, $OPTION_ID, $optQtyB, $unitPriceB);
} catch (InvalidSaleInputException|SaleAbortedException|DuplicateSaleException $e) {
    fwrite(STDERR, '  FAIL  caso 5: la venta con la opción repetida no se pudo guardar: ' . $e->getMessage() . "\n");
    exit(1);
}
$consumedB = $balanceBeforeB - stockBalance($OPT_ITEM_ID, $outletId, $companyId);

if (round($consumedB, 6) !== round($expectedB, 6)) {
    $failures[] = "Caso 5: con qty={$optQtyB} sobre {$parentUnitsB} unidades del padre se esperaban {$expectedB} de stock, se descontaron {$consumedB}"
        . ' — es el contrato que el front invierte al reconstruir la orden (childQty / parentQty)';
} else {
    $rsB = $db->Execute(
        'SELECT itemSoldUnits, itemSoldTotal FROM itemSold WHERE transactionId = ? AND itemId = ?',
        [$saleB['transactionId'], $OPT_ITEM_ID]
    );
    $unitsB = $rsB && !$rsB->EOF ? (float) ($rsB->fields['itemsoldunits'] ?? 0) : -1.0;

    // La PLATA de una opción repetida — el agujero por el que se coló el bug
    // de `expandAddonSelections` corregido el 2026-09-07. Hasta acá el caso 5
    // solo miraba stock y unidades, así que con qty>1 el padre se quedaba con
    // el recargo de las repeticiones Y la hija lo llevaba también: el detalle
    // sumaba más que lo cobrado y el IVA se liquidaba sobre ese exceso. Con
    // qty=1 (caso 3) los dos números coinciden y no se ve.
    $rsAll = $db->Execute(
        'SELECT itemSoldTotal FROM itemSold WHERE transactionId = ?',
        [$saleB['transactionId']]
    );
    $detailSumB = 0.0;
    if ($rsAll !== false) {
        foreach ($rsAll->GetRows() as $row) {
            $detailSumB += (float) $row['itemsoldtotal'];
        }
    }

    if (round($unitsB, 6) !== round($expectedB, 6)) {
        $failures[] = "Caso 5: itemSoldUnits de la hija esperado {$expectedB}, obtenido {$unitsB}";
    } elseif (round($detailSumB, 2) !== round($saleB['subtotal'], 2)) {
        $failures[] = "Caso 5: con la opción repetida, padre + hija = {$detailSumB} pero la caja cobró {$saleB['subtotal']}"
            . ' — el recargo de las repeticiones quedó contado dos veces (una en el padre y otra en la hija)';
    } else {
        echo "[verify_addon_stock] OK caso 5: opción repetida → {$consumedB} unidades de stock y padre + hija = {$detailSumB} = lo cobrado\n";
    }
}

// ── Caso 6: el precio no viaja del cliente ────────────────────────────────
$parentUnitsC = 1.0;
$unitPriceC   = $BASE_PRICE + $PRICE_DELTA; // lo correcto: la caja cobró bien
try {
    $saleC = sellWithAddon(
        $service,
        $PARENT_ID,
        $parentUnitsC,
        $OPTION_ID,
        1,
        $unitPriceC,
        99000.0 // recargo inventado en el payload
    );
} catch (InvalidSaleInputException|SaleAbortedException|DuplicateSaleException $e) {
    fwrite(STDERR, '  FAIL  caso 6: la venta no se pudo guardar: ' . $e->getMessage() . "\n");
    exit(1);
}
$rsC = $db->Execute(
    'SELECT itemSoldTotal FROM itemSold WHERE transactionId = ? AND itemId = ?',
    [$saleC['transactionId'], $OPT_ITEM_ID]
);
$childTotalC = $rsC && !$rsC->EOF ? (float) ($rsC->fields['itemsoldtotal'] ?? 0) : -1.0;
if (round($childTotalC, 2) !== round($PRICE_DELTA, 2)) {
    $failures[] = "Caso 6: la hija se cobró {$childTotalC} con un priceDelta inventado en el payload; el recargo tiene que salir de addon_group_option ({$PRICE_DELTA})";
} else {
    echo "[verify_addon_stock] OK caso 6: el recargo sale de la BD — el priceDelta del payload se ignora\n";
}

// ══════════════════════════════════════════════════════════════════════════
// GRUPO POR CANTIDAD (mig 203) — la caja surtida
// ══════════════════════════════════════════════════════════════════════════

// Stock inicial de los dos sabores, por el mismo camino que arriba.
foreach ([$FLAVOR_A_ID, $FLAVOR_B_ID] as $flavorId) {
    $ok = \Punto\App\Domain\Inventory::manageStock([
        'itemId'        => $flavorId,
        'source'        => 'purchase',
        'count'         => 200,
        'type'          => '+',
        'cogs'          => 200,
        'userId'        => $userId,
        'transactionId' => null,
        'outletId'      => $outletId,
        'locationId'    => null,
        'note'          => 'verify_addon_stock setup caja surtida',
        'date'          => date('Y-m-d H:i:s'),
        'companyId'     => $companyId,
    ]);
    if ($ok === false) {
        fwrite(STDERR, "Setup: manageStock() del sabor {$flavorId} devolvio false — revisar el seed de VERIFY-ADDON-FLAVOR-*\n");
        exit(1);
    }
}

// ── Caso 7: la suma DENTRO del rango pasa; precio = Σ(delta × qty); stock
//    por opción = su qty × unidades del padre ───────────────────────────────
$boxUnits = 2.0;
$qtyA     = 4;                       // recarga 500 c/u
$qtyB     = $BOX_TOTAL - $qtyA;      // 2, sin recargo — suma exacta = 6
$expectedDelta = $FLAVOR_A_DELTA * $qtyA; // el sabor B no suma (D2)
$boxUnitPrice  = $BOX_PRICE + $expectedDelta;

$balA = stockBalance($FLAVOR_A_ID, $outletId, $companyId);
$balB = stockBalance($FLAVOR_B_ID, $outletId, $companyId);

try {
    $saleBox = sellBox($service, $BOX_ID, $boxUnits, [
        ['optionId' => $OPT_A_ID, 'qty' => $qtyA],
        ['optionId' => $OPT_B_ID, 'qty' => $qtyB],
    ], $boxUnitPrice);
} catch (InvalidSaleInputException|SaleAbortedException|DuplicateSaleException $e) {
    fwrite(STDERR, '  FAIL  caso 7: la caja con la suma exacta (' . $BOX_TOTAL . ') fue rechazada: ' . $e->getMessage() . "\n");
    exit(1);
}

$consumedA = $balA - stockBalance($FLAVOR_A_ID, $outletId, $companyId);
$consumedB = $balB - stockBalance($FLAVOR_B_ID, $outletId, $companyId);
$expectedA = $qtyA * $boxUnits;
$expectedB = $qtyB * $boxUnits;

if (round($consumedA, 6) !== round($expectedA, 6) || round($consumedB, 6) !== round($expectedB, 6)) {
    $failures[] = "Caso 7: stock esperado A={$expectedA} B={$expectedB}, descontado A={$consumedA} B={$consumedB}"
        . ' — cada opción descuenta SU qty × unidades del padre, no el total del grupo repartido';
} else {
    $rsBox = $db->Execute(
        'SELECT itemId, itemSoldTotal FROM itemSold WHERE transactionId = ?',
        [$saleBox['transactionId']]
    );
    $totals = [];
    if ($rsBox !== false) {
        foreach ($rsBox->GetRows() as $row) {
            $totals[(string) $row['itemid']] = (float) $row['itemsoldtotal'];
        }
    }
    $childA = $totals[$FLAVOR_A_ID] ?? -1.0;
    $childB = $totals[$FLAVOR_B_ID] ?? -1.0;
    $sum    = array_sum($totals);

    if (round($childA, 2) !== round($FLAVOR_A_DELTA * $qtyA * $boxUnits, 2)) {
        $failures[] = 'Caso 7: el sabor con recargo tenia que llevar ' . ($FLAVOR_A_DELTA * $qtyA * $boxUnits)
            . ", lleva {$childA} — Σ(priceDelta × qty), no delta × opciones elegidas";
    } elseif (round($childB, 2) !== 0.0) {
        $failures[] = "Caso 7: el sabor sin recargo (priceDelta=0, D2) tenia que llevar 0, lleva {$childB}";
    } elseif (round($sum, 2) !== round($saleBox['subtotal'], 2)) {
        $failures[] = "Caso 7: padre + hijas = {$sum}, la caja cobro {$saleBox['subtotal']}";
    } else {
        echo "[verify_addon_stock] OK caso 7: caja surtida de {$BOX_TOTAL} repartida {$qtyA}/{$qtyB} — suma valida, precio = Σ(delta × qty), stock por sabor\n";
    }
}

// ── Caso 8: pasarse del MÁXIMO del grupo se rechaza ───────────────────────
// Ninguna opción tiene maxQty propio: si el tope de suma no existiera, esto
// pasaría sin chistar y la caja de 6 saldría con 7 adentro.
$rejected = false;
$balA8 = stockBalance($FLAVOR_A_ID, $outletId, $companyId);
try {
    sellBox($service, $BOX_ID, 1.0, [
        ['optionId' => $OPT_A_ID, 'qty' => $BOX_TOTAL],
        ['optionId' => $OPT_B_ID, 'qty' => 1],
    ], $BOX_PRICE + $FLAVOR_A_DELTA * $BOX_TOTAL);
} catch (InvalidSaleInputException $e) {
    $rejected = true;
} catch (SaleAbortedException|DuplicateSaleException $e) {
    $failures[] = 'Caso 8: se esperaba InvalidSaleInputException (422), llego ' . get_class($e) . ': ' . $e->getMessage();
    $rejected = true;
}
if (!$rejected) {
    $failures[] = 'Caso 8: una caja con ' . ($BOX_TOTAL + 1) . ' unidades (maximo ' . $BOX_TOTAL . ') se VENDIO'
        . ' — el tope sobre la suma del grupo no se esta aplicando';
} elseif (round(stockBalance($FLAVOR_A_ID, $outletId, $companyId), 6) !== round($balA8, 6)) {
    $failures[] = 'Caso 8: la venta se rechazo pero el stock igual se movio — la validacion tiene que correr ANTES de la transaccion';
} else {
    echo "[verify_addon_stock] OK caso 8: pasarse del maximo del grupo se rechaza (422) y no toca stock\n";
}

// ── Caso 9: quedarse por debajo del MÍNIMO se rechaza ─────────────────────
$rejected = false;
try {
    sellBox($service, $BOX_ID, 1.0, [
        ['optionId' => $OPT_A_ID, 'qty' => $BOX_TOTAL - 1],
    ], $BOX_PRICE + $FLAVOR_A_DELTA * ($BOX_TOTAL - 1));
} catch (InvalidSaleInputException $e) {
    $rejected = true;
} catch (SaleAbortedException|DuplicateSaleException $e) {
    $failures[] = 'Caso 9: se esperaba InvalidSaleInputException (422), llego ' . get_class($e) . ': ' . $e->getMessage();
    $rejected = true;
}
if (!$rejected) {
    $failures[] = 'Caso 9: una caja con ' . ($BOX_TOTAL - 1) . ' unidades (minimo ' . $BOX_TOTAL . ') se VENDIO'
        . ' — el minimo sobre la suma del grupo no se esta aplicando';
} else {
    echo "[verify_addon_stock] OK caso 9: quedarse por debajo del minimo se rechaza (422)\n";
}

// ── Casos 10 y 11: la ORDEN valida con la MISMA regla y persiste la hija ──
// Una orden no es una venta (no toca stock, no lleva plata en las hijas) pero
// pasa por el mismo AddonService::validateSelections. Si la orden aceptara lo
// que la venta rechaza, la cocina prepararía una caja que después no se puede
// cobrar.
$orders = new OrderCoreService($db);

$orderParentQty = 2.0;
$orderQtyA      = 4;
$orderQtyB      = $BOX_TOTAL - $orderQtyA;
$orderId        = null;

try {
    $orderId = $orders->create($companyId, [
        'outletId'   => $outletId,
        'registerId' => $registerId,
        'items'      => [[
            'itemId'     => $BOX_ID,
            'qty'        => $orderParentQty,
            'price'      => $BOX_PRICE + $FLAVOR_A_DELTA * $orderQtyA,
            'selections' => [
                ['optionId' => $OPT_A_ID, 'qty' => $orderQtyA],
                ['optionId' => $OPT_B_ID, 'qty' => $orderQtyB],
            ],
        ]],
    ]);
} catch (\Throwable $e) {
    $failures[] = 'Caso 10: la orden con una caja surtida valida fue rechazada: ' . $e->getMessage();
}

$orderRejected = false;
try {
    $orders->create($companyId, [
        'outletId'   => $outletId,
        'registerId' => $registerId,
        'items'      => [[
            'itemId'     => $BOX_ID,
            'qty'        => 1.0,
            'price'      => $BOX_PRICE,
            'selections' => [['optionId' => $OPT_A_ID, 'qty' => $BOX_TOTAL + 2]],
        ]],
    ]);
} catch (\Throwable $e) {
    $orderRejected = true;
}
if (!$orderRejected) {
    $failures[] = 'Caso 10: la ORDEN acepto una caja de ' . ($BOX_TOTAL + 2) . ' unidades que la venta rechaza'
        . ' — las dos tienen que validar contra el mismo AddonService::validateSelections';
} elseif ($orderId !== null) {
    echo "[verify_addon_stock] OK caso 10: la orden valida la caja surtida igual que la venta (acepta la valida, rechaza el exceso)\n";
}

// ── Caso 11: la hija de la orden lleva optQty × qty del padre ─────────────
if ($orderId !== null) {
    $rsChild = $db->Execute(
        'SELECT itemid, qty, price, pricedelta FROM pos_order_item
          WHERE orderid = ? AND parentorderitemid IS NOT NULL
          ORDER BY itemid',
        [$orderId]
    );
    $children = $rsChild !== false ? $rsChild->GetRows() : [];
    $byItem   = [];
    foreach ($children as $row) {
        $byItem[(string) $row['itemid']] = $row;
    }

    $expectedChildA = $orderQtyA * $orderParentQty;
    $expectedChildB = $orderQtyB * $orderParentQty;
    $gotA = isset($byItem[$FLAVOR_A_ID]) ? (float) $byItem[$FLAVOR_A_ID]['qty'] : -1.0;
    $gotB = isset($byItem[$FLAVOR_B_ID]) ? (float) $byItem[$FLAVOR_B_ID]['qty'] : -1.0;

    if (count($children) !== 2) {
        $failures[] = 'Caso 11: se esperaban 2 lineas hijas en la orden, hay ' . count($children);
    } elseif (round($gotA, 6) !== round($expectedChildA, 6) || round($gotB, 6) !== round($expectedChildB, 6)) {
        $failures[] = "Caso 11: qty de las hijas esperada A={$expectedChildA} B={$expectedChildB}, obtenida A={$gotA} B={$gotB}"
            . ' — es optQty × qty del padre, el contrato que rebuildSelectionsFromOrder invierte al cobrar';
    } elseif (round((float) $byItem[$FLAVOR_A_ID]['price'], 2) !== 0.0) {
        $failures[] = 'Caso 11: la hija de la orden tiene que ir con price=0 (el recargo vive en el padre), lleva '
            . $byItem[$FLAVOR_A_ID]['price'];
    } else {
        echo "[verify_addon_stock] OK caso 11: la orden persiste las hijas con optQty × qty del padre y price=0\n";
    }
}

// ── Resultado ─────────────────────────────────────────────────────────────
if ($failures !== []) {
    fwrite(STDERR, "\n[verify_addon_stock] FALLÓ:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}

echo "[verify_addon_stock] TODOS LOS CASOS OK\n";
exit(0);
