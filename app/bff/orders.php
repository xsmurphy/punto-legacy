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

require_once __DIR__ . '/lib/bff_init.php';

// load.php → ordersTableList (Slice 27): ítems de una mesa/orden.
// Dos sub-modos según flag `json`:
//   json=truthy → tableClose → { list:[items], tags:[...], ids:[...] }
//   (sin json)  → tableDetail → { list: {data, title, subTitle, orderId, type} }
if ($action === 'ordersTableList') {
    $t    = (string) ($get['t'] ?? '');
    $kind = (string) ($get['kind'] ?? 'table');
    $json = !empty($get['json']);
    $ep   = 'v1/orders.php';

    if ($json) {
        $res = bffApiGet($ep, ['resource' => 'tableClose', 't' => $t, 'kind' => $kind], '_jwt');
        if (!$res['ok']) bffFailFromApi($res);
        $d = $res['data'];
        bffJson(['list' => $d['items'] ?? [], 'tags' => $d['tags'] ?? [], 'ids' => $d['ids'] ?? []]);
    } else {
        $res = bffApiGet($ep, ['resource' => 'tableDetail', 't' => $t, 'kind' => $kind], '_jwt');
        if (!$res['ok']) bffFailFromApi($res);
        bffJson(['list' => $res['data']]);
    }
}

// load.php → ordersList sin `t` (Slice 27): lista paginada de órdenes.
// BFF retorna el objeto plano que Mustache consume directamente.
if ($action === 'ordersList') {
    $res = bffApiGet('v1/orders.php', [
        'resource'   => 'list',
        'customerId' => (string) ($get['customerId'] ?? ''),
        'date'       => (string) ($get['date'] ?? ''),
        'limit'      => (string) ($get['limit'] ?? '30'),
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    bffJson($res['data']);
}

// GET userLocation (load.php L588): ubicación del repartidor + próxima delivery.
// El front consume el objeto plano { lat, lng, orderData? } o { error } si 404.
if ($action === 'userLocation') {
    $res = bffApiGet(
        'v1/orders.php',
        ['resource' => 'userLocation', 'id' => (string) ($get['id'] ?? '')],
        '_jwt'
    );
    if (!$res['ok']) {
        $err = is_string($res['error'] ?? null) ? $res['error'] : 'not found';
        bffJson(['error' => $err]);
    }
    bffJson($res['data'] ?? []);
}

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
