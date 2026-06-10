<?php
/**
 * BFF — Cuentas por Cobrar/Pagar.
 *
 *   GET /bff/reports/open_invoices.php?state=income|outcome
 *
 * Gateway fino sobre la API (NO toca BD). El service ya resuelve contactos/deuda/estado, así que
 * el BFF reenvía los datos crudos (rows + kpi) al front, que formatea + arma la tabla. REGLA RAÍZ 2.
 *
 * Las acciones de pago/edición (addPayment, edit) NO pasan por acá: el front las pide al PHP legacy
 * de purchases/transactions vía ?action= (modales del shell).
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$query = array_filter([
    'state' => $_GET['state'] ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/open_invoices.php', $query, '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data'] ?? ['rows' => []]]);
