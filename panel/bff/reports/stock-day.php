<?php
/**
 * BFF — Niveles de Stock por Día.
 *
 *   GET /bff/reports/stock-day.php?date=<datetime>
 *       → filas CRUDAS de stock por ítem. El front formatea + arma la tabla. Cero HTML.
 *
 * Gateway sobre la API (NO toca BD). Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$date = $_GET['date'] ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/stock-day.php', ['date' => $date], '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data']]);
