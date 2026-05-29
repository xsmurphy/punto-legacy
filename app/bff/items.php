<?php
/**
 * /bff/items.php — BFF de ítems del catálogo del POS (Slice 25).
 *
 * Reemplaza el handler itemInfo de load.php (L2612).
 * Decodifica el sobre `?l=`, reenvía a /api/v1/items.php (con cookie _jwt)
 * y devuelve el shape crudo { id, name, price, inventory, ... } que espera
 * el template Mustache #itemInfoTpl del front.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

if ($action === 'itemInfo') {
    $itemId = (string) ($get['i'] ?? '');
    if ($itemId === '') {
        bffJson(['ok' => false, 'error' => 'Falta id'], 422);
    }
    $res = bffApiGet('v1/items.php', ['id' => $itemId, 'resource' => 'info'], '_jwt');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    // El front pasa data directo a Mustache — devolver el objeto crudo (sin envolver).
    bffJson($res['data']);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
