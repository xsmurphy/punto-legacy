<?php
/**
 * /api/v1/schedule.php — agenda/calendario del POS (API compartida del sistema).
 *
 *   PUT    ?id=<transId>                 { time }              → reagenda toDate (updateScheduleTo)
 *   PUT    ?id=<transId>&resource=user   { hour, userId }      → reagenda + reasigna user (updateSchedule)
 *   PUT    ?id=<transId>&resource=session{ from, to, user, client } → agenda sesión + notifica (scheduleSession)
 *   DELETE ?id=<transId>                                       → libera el bloqueo (unlockCalendar)
 *   POST   ?resource=occupied            { users:[], from, to } → userIds ocupados (checkIfUserOccupied)
 *
 * Auth: JWT de tenant. Verbos REST (§22.7). transactionDetails en meta (jsonb, §22.6).
 * Side-effects (email/SMS/push) van en el endpoint, no en el Service.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/ScheduleService.php';

$ctx        = apiAuthTenant();
$companyId   = $ctx['companyId'];
$outletId    = $ctx['outletId'];
$registerId  = $ctx['registerId'];
$userId      = $ctx['userId'];

$svc      = new ScheduleService();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- POST ?resource=occupied (READ con payload de users) ------------------
if ($method === 'POST' && $resource === 'occupied') {
    $users = $_POST['users'] ?? [];
    if (!is_array($users)) {
        $users = [];
    }
    apiOk($svc->checkUserOccupied(
        $companyId,
        $outletId,
        $users,
        (string) ($_POST['from'] ?? ''),
        (string) ($_POST['to'] ?? '')
    ));
}

$transId = trim((string) ($_GET['id'] ?? ''));
if ($transId === '') {
    apiError('Falta id', 422);
}

if ($method === 'DELETE') {
    $res = $svc->unlock($companyId, $transId);
    if (empty($res['ok'])) {
        apiError('No se pudo procesar la operación', 500);
    }
    apiOk($res);
}

if ($method !== 'PUT') {
    apiError('Método no permitido', 405);
}

// --- PUT ?resource=session: agendar sesión + notificaciones ---------------
if ($resource === 'session') {
    $from   = (string) ($_POST['from'] ?? '');
    $to     = (string) ($_POST['to'] ?? '');
    $user   = (string) ($_POST['user'] ?? '');
    $client = (string) ($_POST['client'] ?? '');
    if ($from === '' || $to === '' || $user === '') {
        apiError('Faltan campos requeridos (from, to, user)', 422);
    }

    $res = $svc->session($companyId, $outletId, $registerId, $userId, $transId, $from, $to, $user);
    if (empty($res['ok'])) {
        apiError('No se pudo agendar la sesión', 500);
    }

    // Notificaciones best-effort al cliente + push al usuario.
    try {
        global $compName, $compLogo, $compEmail, $compPhone;
        $contact     = $client !== '' ? getCustomerData($client, 'uid') : null;
        $contactName = $contact ? getCustomerName($contact, 'first') : '';
        $date        = niceDate($from, true, false, false, true);

        $inWindow = defined('TODAY_START') && defined('TODAY_END') && $from > TODAY_START && $to < TODAY_END;
        if ($contact && !empty($contact['email']) && $inWindow) {
            $url  = getShortURL('/screens/scheduleConfirm?s=' . base64_encode($transId . ',' . $companyId));
            $body = 'Hola ' . $contactName . ',<p>Hemos marcado su asistencia el ' . $date
                . '. Puede confirmar o cancelar en el siguiente enlace.</p>' . makeEmailActionBtn($url, 'Confirmar o Cancelar');
            sendEmails([
                'subject'  => '[' . $compName . '] Confirmación',
                'to'       => $contact['email'],
                'fromName' => $compName,
                'data'     => ['message' => $body, 'companyname' => $compName, 'companylogo' => $compLogo],
            ]);
            $msg = '[' . $compName . '] Hola ' . $contactName . ', hemos marcado su asistencia. Puede confirmar o cancelar en: \n' . $url;
            sendSMS(iftn($contact['phone'], $contact['phone2']), $msg);
        }

        sendPush([
            'ids'     => $companyId,
            'message' => 'Tiene cita con ' . $contactName . ' el ' . $date,
            'title'   => defined('COMPANY_NAME') ? COMPANY_NAME : 'Punto',
            'where'   => 'caja',
            'filters' => [
                ['key' => 'userId',    'value' => $user],
                ['key' => 'companyId', 'value' => $companyId],
            ],
        ]);
    } catch (\Throwable $e) {
        error_log('[schedule.session] notificación falló (ignorada): ' . $e->getMessage());
    }

    apiOk(['ok' => true]);
}

// --- PUT ?resource=user: reagendar + reasignar usuario --------------------
if ($resource === 'user') {
    $hour    = (string) ($_POST['hour'] ?? '');
    $newUser = (string) ($_POST['userId'] ?? '');
    if ($hour === '' || $newUser === '') {
        apiError('Faltan campos requeridos (hour, userId)', 422);
    }
    $res = $svc->updateUser($companyId, $transId, $hour, $newUser);
    if (empty($res['ok'])) {
        apiError('No se pudo actualizar la cita', 500);
    }
    apiOk(['ok' => true]);
}

// --- PUT (sin resource): reagendar toDate ---------------------------------
$time = trim((string) ($_POST['time'] ?? ''));
if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
    apiError('Hora inválida (formato HH:MM[:SS])', 422);
}
$res = $svc->rescheduleTo($companyId, $transId, $time);
if (empty($res['ok'])) {
    apiError('No se pudo procesar la operación', 500);
}
apiOk($res);
