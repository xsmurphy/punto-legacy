<?php
/**
 * /bff/tables.php — BFF de las mesas del POS (slice 2 del desacople de /app).
 *
 * Reemplaza el dispatch de renameTable/unReserveTable de action.php. NO toca BD:
 * decodifica el sobre `?l=` que ya manda el front, reenvía a la API v1 (cookie _jwt)
 * y devuelve el shape legacy ({success:"true"}). El front sólo mira éxito/error.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

// El nombre de mesa viene como `t` (rename/unreserve) o `id` (setUserToSpace).
$tableName = (string) ($get['t'] ?? $get['id'] ?? '');
$ep        = 'v1/tables.php';

switch ($action) {
    case 'renameTable':    // PUT ?tableName= { note }
        $res = bffApiPut($ep, ['tableName' => $tableName], ['note' => (string) ($get['note'] ?? '')], '_jwt');
        break;
    case 'unReserveTable': // PUT ?tableName=&resource=reservation
        $res = bffApiPut($ep, ['tableName' => $tableName, 'resource' => 'reservation'], [], '_jwt');
        break;
    case 'setUserToSpace': // PUT ?tableName=&resource=user { userId }
        $res = bffApiPut($ep, ['tableName' => $tableName, 'resource' => 'user'], ['userId' => (string) ($get['uid'] ?? '')], '_jwt');
        break;
    case 'closeTable':     // DELETE ?kind=&del=
        $res = bffApiDelete($ep, ['kind' => (string) ($get['kind'] ?? 'table'), 'del' => (string) ($get['del'] ?? '')], [], '_jwt');
        break;
    default:
        bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['success' => 'true']);
