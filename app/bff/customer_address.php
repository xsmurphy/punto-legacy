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

// --- Escritura: action=customerAddress{Add,Update,Delete,SetDefault} (verbos REST §22.7) ---
$action     = (string) ($get['action'] ?? '');
$customerId = (string) ($get['i'] ?? '');
$addressId  = (string) ($get['id'] ?? '');
$fields     = [
    'customerId' => $customerId,
    'name'       => $get['name']     ?? '',
    'address'    => $get['address']  ?? '',
    'location'   => $get['location'] ?? '',
    'city'       => $get['city']     ?? '',
    'latLng'     => $get['latLng']   ?? '',
];

switch ($action) {
    case 'customerAddressAdd':
        $res = bffApiPost('v1/customer_address.php', $fields, '_jwt');
        break;
    case 'customerAddressUpdate':
        $res = bffApiPut('v1/customer_address.php', ['id' => $addressId], $fields, '_jwt');
        break;
    case 'customerAddressSetDefault':
        $res = bffApiPut('v1/customer_address.php', ['id' => $addressId, 'resource' => 'default'], ['customerId' => $customerId], '_jwt');
        break;
    case 'customerAddressDelete':
        $res = bffApiDelete('v1/customer_address.php', ['id' => $addressId, 'customerId' => $customerId], [], '_jwt');
        break;
    default:
        bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['success' => 'true']);
