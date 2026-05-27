<?php
/**
 * BFF — Reporte de Flujo de Caja.
 *
 *   GET /bff/reports/cashflow.php?from=&to=
 *
 * Gateway fino sobre la API (NO toca BD). El service ya computa los totales del flujo, así que
 * el BFF sólo reenvía los números crudos al front, que formatea + arma KPIs + tabla. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$query = array_filter([
    'from' => $_GET['from'] ?? '',
    'to'   => $_GET['to']   ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/cashflow.php', $query);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data'] ?? []]);
