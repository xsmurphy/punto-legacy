<?php
/**
 * /bff/schedule.php — BFF de la agenda/calendario del POS.
 *
 * Reemplaza de action.php: updateScheduleTo, unlockCalendar, updateSchedule,
 * scheduleSession, checkIfUserOccupied. NO toca BD: decodifica el sobre `?l=`, reenvía a
 * /api/v1/schedule.php (cookie _jwt) con el verbo REST correcto (§22.7) y devuelve el
 * shape legacy: {success:"true"} en las escrituras, o el array de ocupados (occupied).
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

// checkIfUserOccupied: lectura con payload → POST ?resource=occupied → devuelve el array.
if ($action === 'checkIfUserOccupied') {
    $users = is_array($get['users'] ?? null) ? $get['users'] : [];
    $res   = bffApiPost('v1/schedule.php?resource=occupied', [
        'users' => $users,
        'from'  => (string) ($get['from'] ?? ''),
        'to'    => (string) ($get['to'] ?? ''),
    ], '_jwt');
    // El front trata array → ocupados; vacío/error → "nadie ocupado, proceder".
    bffJson(($res['ok'] && is_array($res['data'])) ? $res['data'] : []);
}

// transId viene como `id` (la mayoría) o `lock` (unlockCalendar).
$transId = (string) ($get['id'] ?? $get['lock'] ?? '');

switch ($action) {
    case 'updateScheduleTo': // PUT ?id= { time }
        $res = bffApiPut('v1/schedule.php', ['id' => $transId], ['time' => (string) ($get['t'] ?? '')], '_jwt');
        break;
    case 'updateSchedule':   // PUT ?id=&resource=user { hour, userId }
        $res = bffApiPut('v1/schedule.php', ['id' => $transId, 'resource' => 'user'], [
            'hour'   => (string) ($get['f'] ?? ''),
            'userId' => (string) ($get['ui'] ?? ''),
        ], '_jwt');
        break;
    case 'scheduleSession':  // PUT ?id=&resource=session { from, to, user, client }
        $res = bffApiPut('v1/schedule.php', ['id' => $transId, 'resource' => 'session'], [
            'from'   => (string) ($get['f'] ?? ''),
            'to'     => (string) ($get['t'] ?? ''),
            'user'   => (string) ($get['u'] ?? ''),
            'client' => (string) ($get['c'] ?? ''),
        ], '_jwt');
        break;
    case 'unlockCalendar':   // DELETE ?id=
        $res = bffApiDelete('v1/schedule.php', ['id' => $transId], [], '_jwt');
        break;
    default:
        bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['success' => 'true']);
