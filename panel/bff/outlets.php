<?php
/**
 * BFF — Sucursales.
 *
 *   GET  /bff/outlets.php[?id=<uuid>]                    → lista | una sucursal (crudo).
 *   POST /bff/outlets.php (action=update&id=<uuid> + campos) → actualiza (vía la API).
 *
 * Gateway fino sobre la API (NO toca BD, NO formatea). Reenvía el JWT. El front formatea + arma.
 * El blank-insert y el delete quedan en el PHP legacy `a_outlets.php` vía `?action=`.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $payload = array_filter([
        'action'          => $_POST['action']          ?? '',
        'id'              => $_POST['id']              ?? '',
        'name'            => $_POST['name']            ?? '',
        'address'         => $_POST['address']         ?? '',
        'phone'           => $_POST['phone']           ?? '',
        'email'           => $_POST['email']           ?? '',
        'description'     => $_POST['description']     ?? '',
        'status'          => $_POST['status']          ?? '',
        'billingName'     => $_POST['billingName']     ?? '',
        'ruc'             => $_POST['ruc']             ?? '',
        'whatsApp'        => $_POST['whatsApp']        ?? '',
        'purchaseOrderNo' => $_POST['purchaseOrderNo'] ?? '',
        'latLng'          => $_POST['latLng']          ?? '',
        'tax'             => $_POST['tax']             ?? '',
        'ecom'            => $_POST['ecom']            ?? '',
        'taxIncluded'     => $_POST['taxIncluded']     ?? '',
        'businessHours'   => $_POST['businessHours']   ?? '',
    ], fn($v) => $v !== '');

    $res = bffApiPost('v1/outlets.php', $payload);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

$query = array_filter(['id' => $_GET['id'] ?? ''], fn($v) => $v !== '');

$res = bffApiGet('v1/outlets.php', $query);
if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['ok' => true, 'data' => $res['data']]);
