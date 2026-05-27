<?php
/**
 * BFF — Reporte de Ventas por Medios de Pago.
 *
 *   GET /bff/reports/payment-methods.php?from=<datetime>&to=<datetime>
 *       → { detail: [...], summary: [...] } con NÚMEROS CRUDOS. El front formatea los
 *         montos y arma las tablas (Detallado + Resumido) + el chart. Cero formateo, cero HTML.
 *
 * Gateway sobre la API (NO toca BD): llama GET /API/v1/reports/payment-methods y forwardea
 * los datos crudos (acá iría cálculo/cross-data si hiciera falta). El formateo es del front.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2 / BFF de 3 niveles.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/payment-methods.php', ['from' => $from, 'to' => $to]);
if (!$res['ok']) {
    bffFailFromApi($res);
}

// Datos crudos de la API (números sin formatear). El front formatea y arma el markup.
bffJson(['ok' => true, 'data' => $res['data']]);
