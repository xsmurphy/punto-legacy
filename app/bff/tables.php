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

$opMap = [
    'renameTable'    => 'rename',
    'unReserveTable' => 'unreserve',
    'setUserToSpace' => 'setUserToSpace',
    'closeTable'     => 'closeTable',
];
if (!isset($opMap[$action])) {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

if ($action === 'closeTable') {
    // closeTable matchea por kind/del (any|customer|table), no por nombre de mesa.
    $payload = [
        'op'   => 'closeTable',
        'kind' => (string) ($get['kind'] ?? 'table'),
        'del'  => (string) ($get['del'] ?? ''),
    ];
} else {
    // El nombre de mesa viene como `t` (rename/unreserve) o `id` (setUserToSpace).
    $payload = [
        'op'        => $opMap[$action],
        'tableName' => (string) ($get['t'] ?? $get['id'] ?? ''),
        'note'      => $get['note'] ?? '',
        'userId'    => (string) ($get['uid'] ?? ''),
    ];
}

$res = bffApiPost('v1/tables.php', $payload, '_jwt');
if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['success' => 'true']);
