<?php
/**
 * /bff/orders.php — BFF de órdenes del POS (Slice 7).
 *
 * Reemplaza el dispatch de los siguientes handlers de action.php:
 *   acceptOrder    (L1286) — aceptar una orden (status 2 + notificaciones)
 *   setUserToOrder (L1354) — asignar usuario a una orden
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
    'acceptOrder'    => 'accept',
    'setUserToOrder' => 'assignUser',
];

if (!isset($opMap[$action])) {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$op = $opMap[$action];

$payload = ['op' => $op];

switch ($op) {
    case 'accept':
        $payload['transactionId'] = (string) ($get['id'] ?? '');
        break;

    case 'assignUser':
        $payload['transactionId'] = (string) ($get['id']  ?? '');
        $payload['userId']        = (string) ($get['uid'] ?? '');
        break;
}

$res = bffApiPost('v1/orders.php', $payload, '_jwt');
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['success' => 'true']);
