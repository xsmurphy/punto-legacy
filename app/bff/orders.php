<?php
/**
 * /bff/orders.php — BFF de órdenes del POS (Slice 7).
 *
 * Reemplaza el dispatch de action.php:
 *   acceptOrder (L1286) — aceptar una orden (status 2 + notificaciones)
 *
 * setUserToOrder (L1354) se difirió: escribe transactionDetails (meta jsonb). Va al
 * slice dedicado de meta-JSONB.
 *
 * NO toca BD. Decodifica el sobre `?l=`, mapea a la op correspondiente, reenvía a
 * /api/v1/orders.php (con cookie _jwt) y devuelve el shape legacy { success:"true" }.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

$opMap = [
    'acceptOrder' => 'accept',
];

if (!isset($opMap[$action])) {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$op = $opMap[$action];

$payload = [
    'op'            => $op,
    'transactionId' => (string) ($get['id'] ?? ''),
];

$res = bffApiPost('v1/orders.php', $payload, '_jwt');
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['success' => 'true']);
