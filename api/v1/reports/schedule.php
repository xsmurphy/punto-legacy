<?php
/**
 * REST canónico (API compartida /api) — Reporte de Agendamientos (raw).
 *
 *   GET  /v1/reports/schedule?view=detail|stats|sessions&from=&to=[&ui=&uit=usr|cus]
 *   POST /v1/reports/schedule (action=delete&id=<uuid>) → elimina la cita.
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Reports\ScheduleService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── write: eliminar cita ───────── */
if ($method === 'POST') {
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if ($action !== 'delete') {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    if (!$svc->deleteAppointment($id, (string) COMPANY_ID)) {
        apiError('No se pudo eliminar', 500);
    }
    apiOk(['id' => $id, 'action' => 'delete']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

$view = (string) (validateHttp('view') ?: 'detail');
if (!in_array($view, ['detail', 'stats', 'sessions'], true)) {
    apiError('Vista no soportada', 422);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

$ui  = (string) (validateHttp('ui') ?: '');
$ui  = ($ui !== '' && preg_match($uuidRe, $ui)) ? $ui : '';
$uit = validateHttp('uit') === 'cus' ? 'cus' : 'usr';

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'stats') {
    apiOk($svc->stats(['uit' => $uit, 'ui' => $ui], $from, $to, $roc, $companyId));
} elseif ($view === 'sessions') {
    apiOk($svc->sessions($from, $to, $companyId));
} else {
    apiOk($svc->detail(['ui' => $ui], $from, $to, $roc, $companyId));
}
