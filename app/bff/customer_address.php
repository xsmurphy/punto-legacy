<?php
/**
 * /bff/customer_address.php — BFF de las direcciones de cliente del POS.
 *
 * Reemplaza el dispatch de los handlers customerAddress* de action.php/load.php.
 * NO toca la BD: decodifica el sobre `?l=` que ya manda el front, reenvía a la API
 * v1 (con la cookie _jwt) y traduce la respuesta al shape que el front espera:
 *   - lectura  → { addresses: [...] }   (igual que el legacy load=customerAddress)
 *   - escritura→ { success: "true" }    (el front sólo mira éxito/error)
 *
 * El `?l=` se mantiene como transporte (lo arma ncmHttp.masterUrlParams); acá ya
 * es por-concern, no el IF monolítico. Ver context/05-modulos-clave.md.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];

// --- Lectura: load=customerAddress ------------------------------------------
if (($get['load'] ?? '') === 'customerAddress') {
    $query = ['customerId' => (string) ($get['id'] ?? '')];
    if (!empty($get['aid'])) {
        $query['addressId'] = (string) $get['aid'];
    }
    $res = bffApiGet('v1/customer_address.php', $query, '_jwt');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['addresses' => $res['data'] ?? []]);
}

// --- Escritura: action=customerAddress{Add,Update,Delete,SetDefault} --------
$action = (string) ($get['action'] ?? '');
if (strpos($action, 'customerAddress') === 0) {
    $op = lcfirst(substr($action, strlen('customerAddress'))); // add|update|delete|setDefault

    $payload = [
        'op'         => $op,
        'customerId' => (string) ($get['i'] ?? ''),
        'addressId'  => (string) ($get['id'] ?? ''),
        'name'       => $get['name']     ?? '',
        'address'    => $get['address']  ?? '',
        'location'   => $get['location'] ?? '',
        'city'       => $get['city']     ?? '',
        'latLng'     => $get['latLng']   ?? '',
    ];

    $res = bffApiPost('v1/customer_address.php', $payload, '_jwt');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['success' => 'true']);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
