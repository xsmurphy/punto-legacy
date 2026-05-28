<?php
/**
 * /API/v1/tables.php — mesas/espacios del POS (slice 2 del desacople de /app).
 *
 * Reemplaza los handlers renameTable/unReserveTable del monolito action.php.
 * Gateado por JWT (_jwt); outletId/companyId SIEMPRE del token.
 *
 *   POST op=rename         { tableName, note }
 *   POST op=unreserve      { tableName }
 *   POST op=setUserToSpace { tableName, userId }
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
require_once $appDir . '/lib/TableService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc       = new TableService();
$op        = (string) ($_POST['op'] ?? '');
$tableName = trim((string) ($_POST['tableName'] ?? ''));

if ($tableName === '') {
    apiError('Falta tableName', 422);
}

switch ($op) {
    case 'rename':
        $res = $svc->rename($companyId, $outletId, $tableName, (string) ($_POST['note'] ?? ''));
        break;
    case 'unreserve':
        $res = $svc->unreserve($companyId, $outletId, $tableName);
        break;
    case 'setUserToSpace':
        $assignUserId = trim((string) ($_POST['userId'] ?? ''));
        if ($assignUserId === '') {
            apiError('Falta userId', 422);
        }
        $res = $svc->assignUser($companyId, $outletId, $tableName, $assignUserId);
        if (!empty($res['ok'])) {
            // Notificación al usuario asignado — best-effort: una falla del push
            // (config de push ausente, red, etc.) NO debe romper la asignación.
            try {
                sendPush([
                    'ids'     => COMPANY_ID,
                    'message' => 'El espacio ' . $tableName . ' le fue asignado',
                    'title'   => COMPANY_NAME,
                    'where'   => 'caja',
                    'filters' => [
                        ['key' => 'userId',     'value' => $assignUserId],
                        ['key' => 'isResource', 'value' => 'true'],
                    ],
                ]);
            } catch (\Throwable $e) {
                error_log('[tables.setUserToSpace] push falló (ignorado): ' . $e->getMessage());
            }
        }
        break;
    default:
        apiError('Operación no soportada', 400);
}

if (empty($res['ok'])) {
    apiError('No se pudo procesar la operación', 500);
}
apiOk($res);
