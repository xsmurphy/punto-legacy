<?php
/**
 * /bff/drawer.php — BFF de caja/drawer del POS (Slice 26).
 *
 * Reemplaza el handler loadDrawerList de load.php (L1664).
 * Dos modos según el param `chk` del sobre `?l=`:
 *   chk=1  → GET /api/v1/drawer?resource=check → { success:'true' } | { closed:'Closed' }
 *   (none) → GET /api/v1/drawer                → { data: { list, date, subtotal, ... } }
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

if ($action === 'loadDrawerList') {
    $chk = !empty($get['chk']);

    if ($chk) {
        // Callsite 2 (line 24457): ¿cajón abierto? Front verifica data.closed (truthy = cerrado).
        $res = bffApiGet('v1/drawer.php', ['resource' => 'check'], '_jwt');
        if (!$res['ok']) {
            bffFailFromApi($res);
        }
        if (!empty($res['data']['isOpen'])) {
            bffJson(['success' => 'true']);
        } else {
            bffJson(['closed' => 'Closed']);
        }
    }

    // Callsite 1 (line 24390): resumen completo. Front accede result.data.{subtotal,total,...}
    // PATRÓN BFF-compone: la API expone recursos granulares (open/expenses/income/
    // salesByPayment); el BFF hace fetch del drawer abierto, luego los hijos EN
    // PARALELO con since=drawerOpenDate, y computa el rollup del cierre de caja.
    // (El composite ?resource= de la API queda como backward-compat.)
    $ep = 'v1/drawer.php';

    $openRes = bffApiGet($ep, ['resource' => 'open'], '_jwt');
    if (!$openRes['ok']) {
        bffFailFromApi($openRes);
    }
    if (!empty($openRes['data']['closed'])) {
        bffJson(['closed' => 'Closed']);
    }
    $open  = $openRes['data'];
    $since = (string) ($open['drawerOpenDate'] ?? '');

    // Hijos en paralelo (todos dependen sólo de `since`).
    $parts = bffApiGetMulti([
        'expenses'       => ['path' => $ep, 'query' => ['resource' => 'expenses',       'since' => $since]],
        'income'         => ['path' => $ep, 'query' => ['resource' => 'income',         'since' => $since]],
        'salesByPayment' => ['path' => $ep, 'query' => ['resource' => 'salesByPayment', 'since' => $since]],
    ], '_jwt');

    // FAIL-CLOSED: esto es un rollup FINANCIERO (cierre de caja). Si CUALQUIER hijo
    // falla NO degradamos a cero como customerInfo — un total sub-reportado en
    // silencio es peor que un error. Cortamos con el error del primer hijo caído.
    foreach (['expenses', 'income', 'salesByPayment'] as $k) {
        if (empty($parts[$k]['ok'])) {
            bffFailFromApi($parts[$k]);
        }
    }

    $expenses = $parts['expenses']['data']                   ?? ['amount' => 0];
    $income   = $parts['income']['data']                     ?? ['total' => 0, 'tips' => 0];
    $payments = $parts['salesByPayment']['data']['payments'] ?? [];

    bffJson(['data' => drawerComposeSummary($open, $expenses, $income, $payments)]);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);

/**
 * Rollup del cierre de caja a partir de las piezas granulares. Composición de
 * view-model (el trabajo del BFF). MANTENER EN SYNC con DrawerService::composeSummary
 * (api/lib/services/DrawerService.php) — la misma fórmula vive en ambos lados: la
 * API la usa para su composite backward-compat, el BFF para el path granular. Es el
 * costo del modelo BFF-compone cuando el endpoint COMPUTA un rollup (vs ensamblar
 * puro como customerInfo). Ver §22.12.
 */
function drawerComposeSummary(array $open, array $expenses, array $income, array $payments): array
{
    $cajaInicial   = (float) ($open['drawerOpenAmount'] ?? 0);
    $expenseAmount = (float) ($expenses['amount'] ?? 0);
    $totalIncome   = (float) ($income['total'] ?? 0);
    $totalTips     = (float) ($income['tips'] ?? 0);

    $cashPrice = 0.0;
    $total     = 0.0;
    $return    = 0.0;
    $list      = [['name' => 'Caja Inicial', 'amount' => $cajaInicial]];

    foreach ($payments as $p) {
        $price = (float) ($p['price'] ?? 0);
        if (($p['type'] ?? '') === 'cash') {
            $cashPrice = $price;
        }
        if (($p['type'] ?? '') === 'return') {
            $return += $price;
        } else {
            $total += $price;
            $list[] = ['name' => $p['name'] ?? '', 'amount' => $price];
        }
    }

    $list[] = ['name' => 'Extracciones (Efectivo)', 'amount' => $expenseAmount];
    $list[] = ['name' => 'Ingresos (Efectivo)',     'amount' => $totalIncome];

    return [
        'list'     => $list,
        'date'     => $open['drawerOpenDate'] ?? '',
        'subtotal' => ($cajaInicial + $cashPrice + $totalIncome) - $expenseAmount,
        'total'    => ($cajaInicial + $total + $totalIncome) - $expenseAmount - $return,
        'tips'     => $totalTips,
        'returns'  => -$return,
    ];
}
