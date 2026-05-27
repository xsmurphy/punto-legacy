<?php
/**
 * BFF — Reporte de Ventas por Marca.
 *
 *   GET /bff/reports/brands.php?from=&to=
 *       → { rows: [...+percent+subtotal...], totals: {...} } CRUDO. El front formatea + arma
 *         tabla (con barra de %) + KPIs + treemap. Cero HTML, cero formateo.
 *
 * Gateway sobre la API (NO toca BD) + cálculo del % / subtotal por fila y los totales.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/brands.php', ['from' => $from, 'to' => $to]);
if (!$res['ok']) {
    bffFailFromApi($res);
}

$rows = $res['data'] ?? [];

$allTotal = 0;
foreach ($rows as $r) { $allTotal += (float) ($r['total'] ?? 0); }

$totals = ['usold' => 0, 'tax' => 0, 'discount' => 0, 'subtotal' => 0, 'total' => 0];
foreach ($rows as &$r) {
    $t = (float) ($r['total'] ?? 0);
    $d = (float) ($r['discount'] ?? 0);
    $r['subtotal'] = $t + $d;                                  // subtotal = total + descuento
    $r['percent']  = ($t && $allTotal) ? (int) floor($t * 100 / $allTotal) : 100;
    $totals['usold']    += (float) ($r['usold'] ?? 0);
    $totals['tax']      += (float) ($r['tax'] ?? 0);
    $totals['discount'] += $d;
    $totals['subtotal'] += $t + $d;
    $totals['total']    += $t;
}
unset($r);

bffJson(['ok' => true, 'data' => ['rows' => $rows, 'totals' => $totals]]);
