<?php
/**
 * BFF — Pagos ePOS / vPayments.
 *
 *   GET /bff/reports/vpayments.php?from=&to=
 *
 * Gateway fino sobre la API (NO toca BD ni servicios externos). La API hace la llamada al
 * gateway de pagos; el BFF sólo reenvía los registros crudos + totales al front, que formatea +
 * arma tabla/donut/KPIs. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$query = array_filter([
    'from' => $_GET['from'] ?? '',
    'to'   => $_GET['to']   ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/vpayments.php', $query);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data'] ?? ['rows' => []]]);
