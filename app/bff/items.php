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

    // PATRÓN BFF-compone (§22.12): la API expone `core` (campos del ítem) e
    // `inventory` (stock por outlet) por separado; el BFF los pide EN PARALELO y
    // mergea. ENSAMBLAJE PURO — sin cómputo de rollup en el BFF (a diferencia de
    // drawer §22.12.1, que computa totales financieros).
    $ep    = 'v1/items.php';
    $parts = bffApiGetMulti([
        'core'      => ['path' => $ep, 'query' => ['id' => $itemId, 'resource' => 'core']],
        'inventory' => ['path' => $ep, 'query' => ['id' => $itemId, 'resource' => 'inventory']],
    ], '_jwt');

    // `core` es dependencia DURA: incluye el 404 del ítem (no existe / no es del tenant).
    if (!$parts['core']['ok']) {
        bffFailFromApi($parts['core']);
    }
    $item = $parts['core']['data'];

    // `inventory` es INFORMATIVO → degradación graceful (igual que customerInfo, NO
    // fail-closed como el rollup financiero de drawer): si falla, mostramos el ítem
    // con inventario vacío en vez de bloquear todo el modal de detalle.
    $item['inventory'] = $parts['inventory']['ok'] ? ($parts['inventory']['data']['inventory'] ?? []) : [];

    // El front pasa data directo a Mustache — devolver el objeto crudo (sin envolver).
    bffJson($item);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
