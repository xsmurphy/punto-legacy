<?php
/**
 * /bff/register.php — BFF de la sesión de caja del POS (Slice 10).
 *
 * Reemplaza el dispatch de action.php:
 *   setSession (L1976) — fijar el sessionId de la caja
 *
 * checkSession (L2000) NO se reemplaza: es dead code (el front usa WS bind, no HTTP).
 *
 * NO toca BD. Decodifica `?l=`, reenvía a /api/v1/register.php (cookie _jwt). El front
 * sólo mira éxito/fallo (su onSuccess ignora el body) → devolvemos { success:"true" }.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

if ($action !== 'setSession') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

// PUT = actualiza el sessionId de la caja (§22.7).
$res = bffApiPut('v1/register.php', [], [
    'sessionId' => (string) ($get['id'] ?? ''),
], '_jwt');

if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['success' => 'true']);
