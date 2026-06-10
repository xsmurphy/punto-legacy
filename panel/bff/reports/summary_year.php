<?php
/**
 * BFF — Resumen Anual de Ingresos y Egresos.
 *
 *   GET /bff/reports/summary_year.php?y=<YYYY>  → { year, years, months } CRUDO.
 *
 * Gateway sobre la API (NO toca BD) + DERIVADOS por mes (cross-data, números crudos):
 *   netTotal = salesTotal − discount − returnsTotal − nonAddingTotal
 *   revenue  = netTotal − expensesTotal
 *   margin   = (netTotal>0 && expensesTotal>0) ? max(0, round(revenue/netTotal*100)) : 100
 *              (cuando corre la rama, expenses>0 ⇒ revenue<netTotal ⇒ margin<100 sin clamp alto)
 * + promedio de netTotal. El front formatea + mapea mes→nombre + arma tabla y chart.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$year = $_GET['y'] ?? date('Y');

$res = bffApiGet('v1/reports/summary_year.php', ['y' => $year], '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

$data   = $res['data'] ?? [];
$months = $data['months'] ?? [];

$sumNet  = 0.0;
$nMonths = 0;
$out     = [];
foreach ($months as $m) {
    $netTotal = (float) $m['salesTotal'] - (float) $m['discount'] - (float) $m['returnsTotal'] - (float) $m['nonAddingTotal'];
    $expenses = (float) $m['expensesTotal'];
    $revenue  = round($netTotal - $expenses);

    $margin = 100;
    if ($netTotal > 0 && $expenses > 0) {
        $margin = (int) round($revenue / $netTotal * 100);
        if ($margin < 0) { $margin = 0; }
    }

    $out[] = [
        'month'         => (int) $m['month'],
        'customers'     => (int) $m['customers'],
        'count'         => (int) $m['count'],
        'discount'      => (float) $m['discount'],
        'netTotal'      => $netTotal,
        'expensesTotal' => $expenses,
        'revenue'       => $revenue,
        'margin'        => $margin,
    ];

    $sumNet += $netTotal;
    $nMonths++;
}

$average = $nMonths > 0 ? $sumNet / $nMonths : 0;

bffJson(['ok' => true, 'data' => [
    'year'    => (int) ($data['year'] ?? $year),
    'years'   => $data['years'] ?? [],
    'months'  => $out,
    'average' => $average,
]]);
