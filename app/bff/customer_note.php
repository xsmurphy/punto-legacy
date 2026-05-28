<?php
/**
 * /bff/customer_note.php — BFF de notas de cliente del POS (slice 5).
 *
 * Reemplaza el dispatch de customerNote de action.php. NO toca BD: decodifica el
 * sobre `?l=` del front, reenvía a la API v1 (cookie _jwt) y devuelve {success:"true"}.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];

if (($get['action'] ?? '') !== 'customerNote') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$res = bffApiPost('v1/customer_note.php', [
    'op'         => 'add',
    'customerId' => (string) ($get['i'] ?? ''),
    'text'       => (string) ($get['n'] ?? ''),
], '_jwt');

if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['success' => 'true']);
