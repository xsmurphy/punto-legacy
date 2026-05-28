<?php
/**
 * /api/v1/schedule.php — agenda/calendario del POS (API compartida del sistema).
 *
 *   PUT    ?id=<transId> { time }   → reagenda (mueve toDate a la nueva hora)
 *   DELETE ?id=<transId>            → libera el bloqueo de calendario
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/ScheduleService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

$svc     = new ScheduleService();
$method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$transId = trim((string) ($_GET['id'] ?? ''));

if ($transId === '') {
    apiError('Falta id', 422);
}

switch ($method) {
    case 'PUT': // reagendar
        $time = trim((string) ($_POST['time'] ?? ''));
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
            apiError('Hora inválida (formato HH:MM[:SS])', 422);
        }
        $res = $svc->rescheduleTo($companyId, $transId, $time);
        break;
    case 'DELETE': // liberar bloqueo
        $res = $svc->unlock($companyId, $transId);
        break;
    default:
        apiError('Método no permitido', 405);
}

if (empty($res['ok'])) {
    apiError('No se pudo procesar la operación', 500);
}
apiOk($res);
