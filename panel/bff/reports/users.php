<?php
/**
 * BFF — Reporte de Ventas por Usuarios / Recursos.
 *
 *   GET /bff/reports/users.php?from=&to=
 *       → { rows: [...crudo...], totals: {...} } — números crudos. El front formatea + arma
 *         tabla/KPIs/chart. Cero HTML, cero formateo.
 *
 * Gateway sobre la API (NO toca BD) + cálculo de los totales (suma de las filas).
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/users.php', ['from' => $from, 'to' => $to]);
if (!$res['ok']) {
    bffFailFromApi($res);
}

$rows = $res['data'] ?? [];

// Totales (cálculo del BFF). subtotal = total + descuento (espejo del legacy).
$totals = ['count' => 0, 'usold' => 0, 'discount' => 0, 'subtotal' => 0, 'total' => 0];
foreach ($rows as $r) {
    $totals['count']    += (int) ($r['count'] ?? 0);
    $totals['usold']    += (float) ($r['usold'] ?? 0);
    $totals['discount'] += (float) ($r['discount'] ?? 0);
    $totals['total']    += (float) ($r['total'] ?? 0);
    $totals['subtotal'] += (float) ($r['total'] ?? 0) + (float) ($r['discount'] ?? 0);
}

bffJson(['ok' => true, 'data' => ['rows' => $rows, 'totals' => $totals]]);
