<?php
/**
 * /bff/schedule.php — BFF de la agenda/calendario del POS (slice 4).
 *
 * Reemplaza el dispatch de updateScheduleTo/unlockCalendar de action.php. NO toca BD:
 * decodifica el sobre `?l=` del front, reenvía a la API v1 (cookie _jwt) y devuelve el
 * shape legacy ({success:"true"}).
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

$opMap = ['updateScheduleTo' => 'rescheduleTo', 'unlockCalendar' => 'unlock'];
if (!isset($opMap[$action])) {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

// transId viene como `id` (updateScheduleTo) o `lock` (unlockCalendar).
$payload = [
    'op'      => $opMap[$action],
    'transId' => (string) ($get['id'] ?? $get['lock'] ?? ''),
    'time'    => (string) ($get['t'] ?? ''),
];

$res = bffApiPost('v1/schedule.php', $payload, '_jwt');
if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['success' => 'true']);
