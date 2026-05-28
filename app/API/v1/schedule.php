<?php
/**
 * /API/v1/schedule.php — agenda/calendario del POS (slice 4 del desacople de /app).
 *
 * Reemplaza updateScheduleTo/unlockCalendar del monolito action.php. JWT-gated;
 * companyId SIEMPRE del token.
 *
 *   POST op=rescheduleTo { transId, time }
 *   POST op=unlock       { transId }
 *
 * Envelope canónico { ok, data } / { ok:false, error }.
 */

session_start();

$appDir = dirname(__DIR__, 2);
chdir($appDir);

require_once $appDir . '/includes/cors.php';
require_once $appDir . '/includes/jwt_middleware.php';
require_once __DIR__ . '/../lib/response.php';

$rateLimiterId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
require_once $appDir . '/head.php';

if (!jwtAuthenticate()) {
    apiError('Autenticación requerida', 401);
}

$companyId  = AUTHED_COMPANY_ID;
$outletId   = AUTHED_OUTLET_ID;
$userId     = AUTHED_USER_ID;
$registerId = AUTHED_REGISTER_ID;
$roleId     = AUTHED_ROLE_ID;

if (!checkCompanyStatus($companyId)) {
    apiError('Company Blocked', 403);
}

require_once $appDir . '/data.php';
require_once $appDir . '/lib/ScheduleService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc     = new ScheduleService();
$op      = (string) ($_POST['op'] ?? '');
$transId = trim((string) ($_POST['transId'] ?? ''));

if ($transId === '') {
    apiError('Falta transId', 422);
}

switch ($op) {
    case 'rescheduleTo':
        $time = trim((string) ($_POST['time'] ?? ''));
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            apiError('Hora inválida (formato HH:MM[:SS])', 422);
        }
        $res = $svc->rescheduleTo($companyId, $transId, $time);
        break;
    case 'unlock':
        $res = $svc->unlock($companyId, $transId);
        break;
    default:
        apiError('Operación no soportada', 400);
}

if (empty($res['ok'])) {
    apiError('No se pudo procesar la operación', 500);
}
apiOk($res);
