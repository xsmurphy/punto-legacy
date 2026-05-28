<?php
/**
 * /bff/currencies.php — BFF de tasas de cambio del POS (Slice 12).
 *
 * Reemplaza el dispatch de setCurrencies (action.php L79). NO toca BD: reenvía a
 * /api/v1/currencies.php (cookie _jwt) y devuelve el shape legacy { success: [...] }
 * (el front lee result.success).
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

if ($action !== 'setCurrencies') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$res = bffApiGet('v1/currencies.php', [], '_jwt');
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['success' => is_array($res['data']) ? $res['data'] : []]);
