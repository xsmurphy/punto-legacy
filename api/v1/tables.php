<?php
/**
 * /api/v1/tables.php — mesas/espacios del POS (API compartida del sistema).
 *
 *   POST op=rename         { tableName, note }
 *   POST op=unreserve      { tableName }
 *   POST op=setUserToSpace { tableName, userId }
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/TableService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];

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
            // Notificación best-effort: una falla del push NO rompe la asignación.
            try {
                sendPush([
                    'ids'     => $companyId,
                    'message' => 'El espacio ' . $tableName . ' le fue asignado',
                    'title'   => defined('COMPANY_NAME') ? COMPANY_NAME : 'Punto',
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
