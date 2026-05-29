<?php
/**
 * /bff/customers.php — BFF de lecturas de cliente del POS.
 *
 * Reemplaza el handler `customerInfo` de app/load.php. NO toca BD: decodifica el
 * sobre `?l=`, reenvía a /api/v1/customers.php (cookie _jwt) y devuelve el objeto
 * plano del resumen del cliente (shape legacy de customerInfo).
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

if ($action === 'customerInfo') {
    $res = bffApiGet('v1/customers.php', [
        'resource' => 'info',
        'id'       => (string) ($get['id'] ?? ''),
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    bffJson($res['data']);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
