<?php
/**
 * /bff/orders.php — BFF de órdenes del POS (Slice 7).
 *
 * Reemplaza el dispatch de action.php/load.php:
 *   acceptOrder          (action.php L1286) — aceptar una orden (status 2 + notificaciones)
 *   transferOrderToOutlet (action.php L575) — mover una orden a otro outlet
 *   setUserToOrder       (action.php L1354) — asignar usuario (escribe transactionDetails en meta jsonb)
 *   customerHasOrders    (load.php L1634)   — ¿el cliente tiene órdenes abiertas? → objeto plano
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

// GET customerHasOrders: el front consume el objeto plano { hasOrders: bool }.
if ($action === 'customerHasOrders') {
    $res = bffApiGet(
        'v1/orders.php',
        ['resource' => 'customerHasOrders', 'customerId' => (string) ($get['id'] ?? '')],
        '_jwt'
    );
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson($res['data'] ?? ['hasOrders' => false]);
}

// action.php → PUT con ?resource= (transiciones de estado, §22.7)
switch ($action) {
    case 'acceptOrder': // PUT ?id=&resource=accept
        $res = bffApiPut('v1/orders.php', ['id' => (string) ($get['id'] ?? ''), 'resource' => 'accept'], [], '_jwt');
        break;
    case 'transferOrderToOutlet': // PUT ?id=&resource=outlet { outletId }
        // Legacy: outletFromId = outlet DESTINO; orderId = la orden a mover.
        $res = bffApiPut(
            'v1/orders.php',
            ['id' => (string) ($get['orderId'] ?? ''), 'resource' => 'outlet'],
            ['outletId' => (string) ($get['outletFromId'] ?? '')],
            '_jwt'
        );
        break;
    case 'setUserToOrder': // PUT ?id=&resource=user { userId }
        $res = bffApiPut(
            'v1/orders.php',
            ['id' => (string) ($get['id'] ?? ''), 'resource' => 'user'],
            ['userId' => (string) ($get['uid'] ?? '')],
            '_jwt'
        );
        break;
    default:
        bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['success' => 'true']);
