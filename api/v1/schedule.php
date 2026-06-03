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
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\ScheduleService;

$ctx        = apiAuthTenant();
$companyId   = $ctx['companyId'];
$outletId    = $ctx['outletId'];
$registerId  = $ctx['registerId'];
$userId      = $ctx['userId'];

$svc      = new ScheduleService(TenantContext::fromAuth($ctx));
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

// --- GET ?resource=sessions: lista de paquetes de sesiones de un cliente (Slice 30)
if ($method === 'GET' && $resource === 'sessions') {
    $encCid = trim((string) ($_GET['customerId'] ?? ''));
    if ($encCid === '') {
        apiError('Falta customerId', 422);
    }
    $date = trim((string) ($_GET['date'] ?? '')) ?: null;
    apiOk($svc->getSessionsList($companyId, $encCid, $date));
}

// --- GET ?resource=calendar: vistas del calendario (Slice 41 — strangler de calendar_*) ---
// mode: 'resources' | 'week' | 'agenda' | 'month'
//   resources/week: { slots: [...] }      — array de slots para ncmCalendar
//   agenda:         { agenda: [...] }     — array agrupado por día
//   month:          { html: "<...>" }     — HTML del calendario mensual
if ($method === 'GET' && $resource === 'calendar') {
    $mode = (string) ($_GET['mode'] ?? 'resources');
    $date = trim((string) ($_GET['date'] ?? '')) ?: date('Y-m-d');

    if ($mode === 'resources' || $mode === 'week') {
        $weekRange = trim((string) ($_GET['weekRange'] ?? '')) ?: null;
        $resourceU = trim((string) dec($_GET['user'] ?? '')) ?: null;
        apiOk(['slots' => $svc->getCalendarSlots($mode, $date, $weekRange, $resourceU, $companyId, $outletId)]);
    }

    if ($mode === 'agenda') {
        apiOk(['agenda' => $svc->getCalendarAgenda($date, $companyId, $outletId)]);
    }

    if ($mode === 'month') {
        $counts = $svc->getCalendarMonthCounts($date, $companyId, $outletId);

        // HTML rendering (presentation logic; mismo markup que el legacy load.php?load=calendar_month).
        $month        = date('m', strtotime($date));
        $year         = date('Y', strtotime($date));
        $daysOfWeek   = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
        $firstDay     = mktime(0, 0, 0, (int) $month, 1, (int) $year);
        $numberDays   = (int) date('t', $firstDay);
        $dateComp     = getdate($firstDay);
        $dayOfWeek    = $dateComp['wday'];
        $monthPadded  = str_pad($month, 2, '0', STR_PAD_LEFT);

        $calendar  = "<table class='table scheduler table-bordered m-b-lg'><tr>";
        foreach ($daysOfWeek as $day) {
            $calendar .= '<th class="dk" style="width:14.3%;">' . $day . '</th>';
        }
        $calendar .= '</tr><tr>';

        if ($dayOfWeek > 0) {
            $calendar .= "<td colspan='$dayOfWeek' class='bg-light dker'>&nbsp;</td>";
        }

        $currentDay = 1;
        while ($currentDay <= $numberDays) {
            if ($dayOfWeek === 7) {
                $dayOfWeek = 0;
                $calendar .= '</tr><tr>';
            }
            $dayPadded = str_pad((string) $currentDay, 2, '0', STR_PAD_LEFT);
            $cellDate  = "$year-$monthPadded-$dayPadded";
            $isToday   = date('Y-m-d') === $cellDate;
            $count     = $counts[$cellDate] ?? 0;

            $calendar .= '<td class="ncmCalendarDay ' . ($isToday ? 'bg-white' : '') . ' scrolleable pointer clickeable" rel="' . $cellDate . '" data-type="calendarView" data-mode="calendar_resources" data-date="' . $cellDate . '" style="height:100px;">'
                . ' <div>'
                . '   <span class="badge ' . ($isToday ? 'bg-info' : 'bg-white') . '">' . $currentDay . '</span>'
                . ' </div>'
                . ' <div class="text-center">'
                . '   <div class="font-bold h2">' . $count . '</div>'
                . '   <div class="text-xs text-muted">Reserva(s)</div>'
                . ' </div>'
                . '</td>';

            $currentDay++;
            $dayOfWeek++;
        }

        if ($dayOfWeek !== 7) {
            $remaining = 7 - $dayOfWeek;
            $calendar .= "<td colspan='$remaining' class='bg-light dker'>&nbsp;</td>";
        }
        $calendar .= '</tr></table>';

        $title = !empty($_GET['solo']) ? '' : buildCalendarTop(['date' => $date, 'current' => 'month']);
        $bottomSpace = '<div class="col-xs-12 wrapper-xl"></div><div class="col-xs-12 wrapper-xl"></div>';
        $html  = '<div class="table-responsive bg-light panel no-padder m-b-lg" style="overflow-y:hidden;">' . $title . $calendar . $bottomSpace . '</div>';

        apiOk(['html' => $html]);
    }

    apiError('mode inválido (resources|week|agenda|month)', 422);
}

// --- GET ?resource=agenda: lista de citas/turnos (type 13) (Slice 31)
if ($method === 'GET' && $resource === 'agenda') {
    $encCid = trim((string) ($_GET['customerId'] ?? '')) ?: null;
    $date   = trim((string) ($_GET['date'] ?? '')) ?: null;
    $limit  = max(1, (int) ($_GET['limit'] ?? 30));
    apiOk($svc->getAgendaList($companyId, $outletId, $encCid, $date, $limit));
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
