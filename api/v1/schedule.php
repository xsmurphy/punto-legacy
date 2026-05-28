<?php
/**
 * /api/v1/schedule.php — agenda/calendario del POS (API compartida del sistema).
 *
 *   POST op=rescheduleTo { transId, time }
 *   POST op=unlock       { transId }
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/ScheduleService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

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
