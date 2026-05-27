<?php
/**
 * BFF — Niveles de Stock (multi-depósito).
 *
 *   GET /bff/reports/stock.php → { needsOutlet, rows } CRUDO. El front formatea + arma la
 *       tabla anidada (ítem + depósitos + principal) y calcula los colores/% de las barras.
 *
 * Gateway sobre la API (NO toca BD). Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$res = bffApiGet('v1/reports/stock.php');
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data']]);
