<?php
/**
 * /bff/schedule.php — BFF de la agenda/calendario del POS.
 *
 * Reemplaza de action.php: updateScheduleTo, unlockCalendar, updateSchedule,
 * scheduleSession, checkIfUserOccupied. NO toca BD: decodifica el sobre `?l=`, reenvía a
 * /api/v1/schedule.php (cookie _jwt) con el verbo REST correcto (§22.7) y devuelve el
 * shape legacy: {success:"true"} en las escrituras, o el array de ocupados (occupied).
 */

require_once __DIR__ . '/lib/bff_init.php';

// load.php → calendar_* (Slice 41): vistas del calendario. El front pasa `load` con
// el mode (calendar_resources_json | calendar_week_json | calendar_agenda_json | calendar_month).
// Mapeamos a $action por compatibilidad con el dispatcher.
$load = (string) ($get['load'] ?? '');
if ($action === '' && str_starts_with($load, 'calendar_')) {
    $action = $load;
}

// calendar_resources_json / calendar_week_json: el front consume `data.data` (legacy
// emitía jsonDieMsg($jsonOut,200,'data') → {data:[...]}). Adapt al shape moderno {slots}.
if ($action === 'calendar_resources_json' || $action === 'calendar_week_json') {
    $mode = ($action === 'calendar_week_json') ? 'week' : 'resources';
    $res  = bffApiGet('v1/schedule.php', [
        'resource'  => 'calendar',
        'mode'      => $mode,
        'date'      => (string) ($get['date']      ?? ''),
        'weekRange' => (string) ($get['weekRange'] ?? ''),
        'user'      => (string) ($get['resource']  ?? ''),
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    bffJson(['data' => $res['data']['slots'] ?? []]);
}

if ($action === 'calendar_agenda_json') {
    $res = bffApiGet('v1/schedule.php', [
        'resource' => 'calendar',
        'mode'     => 'agenda',
        'date'     => (string) ($get['date'] ?? ''),
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    bffJson(['data' => $res['data']['agenda'] ?? []]);
}

// calendar_month: el legacy hacía echo $html directo. El BFF re-emite como text/html.
if ($action === 'calendar_month') {
    $res = bffApiGet('v1/schedule.php', [
        'resource' => 'calendar',
        'mode'     => 'month',
        'date'     => (string) ($get['date'] ?? ''),
        'solo'     => !empty($get['solo']) ? '1' : '',
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    header('Content-Type: text/html; charset=utf-8');
    echo (string) ($res['data']['html'] ?? '');
    exit;
}

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

// load.php → sessionsList (Slice 30): lista de paquetes de sesiones, objeto plano.
if ($action === 'sessionsList') {
    $res = bffApiGet('v1/schedule.php', [
        'resource'   => 'sessions',
        'customerId' => (string) ($get['customerId'] ?? ''),
        'date'       => (string) ($get['date'] ?? ''),
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    bffJson($res['data']);
}

// load.php → agendaList (Slice 31): lista de citas/turnos (type 13), objeto plano.
if ($action === 'agendaList') {
    $res = bffApiGet('v1/schedule.php', [
        'resource'   => 'agenda',
        'customerId' => (string) ($get['customerId'] ?? ''),
        'date'       => (string) ($get['date'] ?? ''),
        'limit'      => (string) ($get['limit'] ?? '30'),
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    bffJson($res['data']);
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
