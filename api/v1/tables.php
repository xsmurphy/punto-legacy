<?php
/**
 * /api/v1/tables.php — mesas/espacios del POS (API compartida del sistema).
 *
 *   PUT    ?tableName=<n>                      { note }   → renombra (nota)
 *   PUT    ?tableName=<n>&resource=reservation            → libera reserva (status 1)
 *   PUT    ?tableName=<n>&resource=user        { userId } → asigna usuario al espacio
 *   DELETE ?kind=<any|customer|table>&del=<v>             → cierra la mesa
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7) — la mesa se
 * identifica por ?tableName= (no es un UUID, es el nro/nombre de mesa).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/TableService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];

$svc      = new TableService();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- DELETE: cerrar mesa (matchea por kind/del, no por tableName) ---------
if ($method === 'DELETE') {
    $kind = (string) ($_GET['kind'] ?? 'table');
    $del  = trim((string) ($_GET['del'] ?? ''));
    if ($del === '') {
        apiError('Falta del', 422);
    }
    $res = $svc->closeTable($companyId, $outletId, $kind, $del);
    if (empty($res['ok'])) {
        apiError('No se pudo cerrar la mesa', 500);
    }
    apiOk($res);
}

if ($method !== 'PUT') {
    apiError('Método no permitido', 405);
}

$tableName = trim((string) ($_GET['tableName'] ?? ''));
if ($tableName === '') {
    apiError('Falta tableName', 422);
}

switch ($resource) {
    case '': // rename (nota)
        $res = $svc->rename($companyId, $outletId, $tableName, (string) ($_POST['note'] ?? ''));
        break;
    case 'reservation': // liberar reserva
        $res = $svc->unreserve($companyId, $outletId, $tableName);
        break;
    case 'user': // asignar usuario
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
        apiError('Sub-recurso no soportado', 400);
}

if (empty($res['ok'])) {
    apiError('No se pudo procesar la operación', 500);
}
apiOk($res);
