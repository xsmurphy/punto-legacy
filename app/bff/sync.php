<?php
/**
 * /bff/sync.php — BFF de checks de sincronización offline del POS (Slice 8).
 *
 * Reemplaza el dispatch de action.php:
 *   chkDeletedItems     (L166) — itemIds borrados
 *   chkDeletedCustomers (L196) — contactIds borrados
 *
 * El front manda la acción en `?l=` y la lista de IDs en POST (`ids[]`). NO toca BD:
 * reenvía a /api/v1/sync.php (cookie _jwt) y replica el shape legacy de jsonDieMsg:
 *   - con borrados → { success: [ids] }   (HTTP 200)
 *   - sin borrados → { error: "No data found" } (HTTP 404)
 */

require_once __DIR__ . '/lib/bff_init.php';

$resourceMap = [
    'chkDeletedItems'     => 'items',
    'chkDeletedCustomers' => 'customers',
];

if (!isset($resourceMap[$action])) {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

// Los IDs vienen en el POST body (igual que el legacy validateHttp('ids','post')).
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}

// POST con ?resource= (lectura con payload grande, §22.7).
$res = bffApiPost('v1/sync.php?resource=' . $resourceMap[$action], ['ids' => $ids], '_jwt');
if (!$res['ok']) {
    bffFailFromApi($res);
}

$deleted = is_array($res['data']) ? $res['data'] : [];

// Shape legacy: array no vacío → success; vacío → 404 (el front lo trata como "nada que purgar").
if (!empty($deleted)) {
    bffJson(['success' => $deleted], 200);
}
bffJson(['error' => 'No data found'], 404);
