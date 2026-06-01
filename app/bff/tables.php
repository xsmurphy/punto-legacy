<?php
/**
 * /bff/tables.php — BFF de las mesas del POS (slice 2 del desacople de /app).
 *
 * Reemplaza el dispatch de renameTable/unReserveTable de action.php. NO toca BD:
 * decodifica el sobre `?l=` que ya manda el front, reenvía a la API v1 (cookie _jwt)
 * y devuelve el shape legacy ({success:"true"}). El front sólo mira éxito/error.
 */

require_once __DIR__ . '/lib/bff_init.php';

// Sin action = listado de mesas (GET tablesJson).
if ($action === '') {
    $res = bffApiGet('v1/tables.php');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['table' => $res['data'] ?? []]);
}

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
    case 'joinSpaces':     // PUT ?resource=join { from, to }
        $res = bffApiPut($ep, ['resource' => 'join'], ['from' => (string) ($get['tFrom'] ?? ''), 'to' => (string) ($get['tTo'] ?? '')], '_jwt');
        break;
    case 'moveOrders':     // PUT ?resource=move { from, to }
        $res = bffApiPut($ep, ['resource' => 'move'], ['from' => (string) ($get['tFrom'] ?? ''), 'to' => (string) ($get['tTo'] ?? '')], '_jwt');
        break;
    default:
        bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['success' => 'true']);
