<?php
/**
 * BFF — Reporte de Clientes.
 *
 *   GET /bff/reports/customers.php?from=&to=  → { rows, global, chart } CRUDO.
 *
 * Gateway sobre la API (NO toca BD) + DERIVADOS (cross-data, números crudos):
 *   por fila: net = grossTotal − discount, average = net / count
 *   global:   sales=Σgross, qty=Σcount, discount=Σdiscount, total=Σnet, average=Σnet/Σqty
 *   chart:    top 15 clientes por grossTotal → { label, data }
 * El front formatea TODO + arma tabla/KPIs/chart. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/customers.php', ['from' => $from, 'to' => $to]);
if (!$res['ok']) {
    bffFailFromApi($res);
}

$rows = $res['data']['rows'] ?? [];

$gSales = 0.0; $gQty = 0; $gDiscount = 0.0; $gTotal = 0.0;
$chartLabel = []; $chartData = [];
$out = [];
$i = 0;
foreach ($rows as $r) {
    $gross = (float) $r['grossTotal'];
    $disc  = (float) $r['discount'];
    $count = (int)   $r['count'];
    $net   = $gross - $disc;
    $avg   = $count > 0 ? $net / $count : 0;

    $r['net']     = $net;     // Total (neto)
    $r['subtotal'] = $gross;  // Subtotal (bruto)
    $r['average'] = $avg;     // Gasto promedio
    $out[] = $r;

    $gSales    += $gross;
    $gQty      += $count;
    $gDiscount += $disc;
    $gTotal    += $net;

    // Top 15: las filas ya vienen ordenadas por grossTotal DESC desde la API (ORDER BY grosstotal DESC).
    if ($i < 15) {
        $chartLabel[] = (string) $r['name'];
        $chartData[]  = $gross;
    }
    $i++;
}

bffJson(['ok' => true, 'data' => [
    'rows'   => $out,
    'global' => [
        'sales'    => $gSales,
        'qty'      => $gQty,
        'discount' => $gDiscount,
        'total'    => $gTotal,
        'average'  => $gQty > 0 ? $gTotal / $gQty : 0,
    ],
    'chart'  => ['label' => $chartLabel, 'data' => $chartData],
]]);
