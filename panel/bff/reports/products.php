<?php
/**
 * BFF — Reporte de Artículos / Productos.
 *
 *   GET /bff/reports/products.php?view=general|detail|combos&from=&to=[&cusId&usrId&itmId&month&year&src]
 *
 * Gateway sobre la API (NO toca BD) + DERIVADOS (cross-data, números CRUDOS):
 *   - utilidad por fila: general = (total − COGS) − comisión;  detail/combos = (total − tax) − COGS − comisión.
 *   - general: totales (con resta de internas), KPIs (subtotal/cogs/otros/utilidad) + comparación
 *     vs período anterior (raw now/prev + flag inverted), y chart top-20 por unidades con línea previa.
 * El front formatea TODO + arma tablas/tabs/KPIs/chart. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$view = $_GET['view'] ?? 'general';
$query = array_filter([
    'view'  => $view,
    'from'  => $_GET['from']  ?? '',
    'to'    => $_GET['to']    ?? '',
    'cusId' => $_GET['cusId'] ?? '',
    'usrId' => $_GET['usrId'] ?? '',
    'itmId' => $_GET['itmId'] ?? '',
    'month' => $_GET['month'] ?? '',
    'year'  => $_GET['year']  ?? '',
    'src'   => $_GET['src']   ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/products.php', $query, '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}
$data = $res['data'] ?? [];
$rows = $data['rows'] ?? [];

/* ─────────── detail / combos: la utilidad por fila ya viene del service (motor ERP) ─────────── */
if ($view === 'detail' || $view === 'combos') {
    bffJson(['ok' => true, 'data' => ['rows' => $rows, 'month' => $data['month'] ?? false]]);
}

/* ─────────── general: totales + KPIs + chart (la utilidad por fila ya viene del service) ─────────── */
$tTotal = 0.0; $tCOGS = 0.0; $tComission = 0.0; $tDiscount = 0.0; $tUsold = 0.0; $tUtility = 0.0;
foreach ($rows as $r) {
    $tTotal     += $r['total'];
    $tCOGS      += $r['cogs'];
    $tComission += $r['comission'];
    $tDiscount  += $r['discount'];
    $tUsold     += $r['usold'];
    $tUtility   += $r['utility'];
}

// Resta de ventas internas (sólo qty/discount/total; tax queda en 0 como el legacy).
$internals = $data['internals'] ?? ['total' => 0, 'qty' => 0, 'tax' => 0, 'discount' => 0];
$tUsold    -= $internals['qty'];
$tDiscount -= $internals['discount'];
$tTotal    -= $internals['total'];

$prev = $data['prev'] ?? ['total' => 0, 'cogs' => 0, 'tax' => 0, 'discount' => 0, 'comission' => 0, 'utility' => 0];

// "Otros costos" = descuentos + comisiones (+ tax, que es 0).
$otros     = $tDiscount + $tComission;
$prevOtros = ($prev['discount'] ?? 0) + ($prev['comission'] ?? 0) + ($prev['tax'] ?? 0);

// Chart: top-20 por unidades (total > 0), con la línea de unidades del período anterior.
$prevByItem = $data['prevByItem'] ?? [];
$label = []; $cur = []; $back = []; $n = 0;
foreach ($rows as $r) {
    if ($n >= 20) { break; }
    if ($r['total'] <= 0) { continue; }
    $label[] = (string) $r['name'];
    $cur[]   = $r['usold'];
    $back[]  = $prevByItem[$r['id']] ?? 0;
    $n++;
}

bffJson(['ok' => true, 'data' => [
    'rows'  => $rows,
    'month' => $data['month'] ?? false,
    'kpi'   => [
        'subtotal' => ['now' => $tTotal,   'prev' => $prev['total'],   'inverted' => false],
        'cogs'     => ['now' => $tCOGS,    'prev' => $prev['cogs'],    'inverted' => true],
        'otros'    => ['now' => $otros,    'prev' => $prevOtros,       'inverted' => true],
        'utility'  => ['now' => $tUtility, 'prev' => $prev['utility'], 'inverted' => false],
    ],
    'chart' => ['label' => $label, 'data' => $cur, 'dataPrev' => $back],
]]);
