<?php
/**
 * /api/v1/tables.php — espacios del POS (API compartida del sistema).
 *
 *   PUT    ?tableName=<n>                      { note }   → renombra (nota)
 *   PUT    ?tableName=<n>&resource=reservation            → libera reserva (status 1)
 *   PUT    ?tableName=<n>&resource=user        { userId } → asigna usuario al espacio
 *   DELETE ?kind=<any|customer|table>&del=<v>             → cierra el espacio
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7) — el espacio se
 * identifica por ?tableName= (no es un UUID, es el nro/nombre de espacio).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/TableService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\TableService;

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];

$svc      = new TableService(TenantContext::fromAuth($ctx));
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- GET: listar espacios abiertos del outlet (tablesJson) -------------------
if ($method === 'GET') {
    apiOk($svc->listTables($companyId, $outletId));
}

// --- DELETE: cerrar espacio (matchea por kind/del, no por tableName) ---------
if ($method === 'DELETE') {
    $kind = (string) ($_GET['kind'] ?? 'table');
    $del  = trim((string) ($_GET['del'] ?? ''));
    if ($del === '') {
        apiError('Falta del', 422);
    }
    $res = $svc->closeTable($companyId, $outletId, $kind, $del);
    if (empty($res['ok'])) {
        apiError('No se pudo cerrar el espacio', 500);
    }
    apiOk($res);
}

// --- PUT ?resource=join|move: unir espacios / mover órdenes (joinSpaces/moveOrders) ---
if ($method === 'PUT' && ($resource === 'join' || $resource === 'move')) {
    $from = trim((string) ($_POST['from'] ?? ''));
    $to   = trim((string) ($_POST['to'] ?? ''));
    if ($from === '' || $to === '') {
        apiError('Faltan from/to', 422);
    }
    $res = $resource === 'join'
        ? $svc->joinSpaces($companyId, $outletId, $from, $to)
        : $svc->moveOrders($companyId, $outletId, $ctx['registerId'], $ctx['userId'], $from, $to);
    if (empty($res['ok'])) {
        apiError($res['reason'] ?? 'No se pudo procesar la operación', $res['code'] ?? 500);
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
