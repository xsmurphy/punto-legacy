<?php
/**
 * /api/v1/spaces.php — CRUD de espacios + editor de layout (space, mig 80,
 * context/15-espacios-module-plan.md F0+F1). Nombre nuevo a propósito — NO
 * pisa el legacy `api/v1/tables.php` (espacios/transaction type=11, dominio
 * distinto, ver api/lib/services/TableService.php).
 *
 *   GET    /v1/spaces?outletId=<uuid>                     → lista simple (sectorId? filtra)
 *   GET    /v1/spaces?outletId=<uuid>&resource=state       → plano operativo (listWithState)
 *   GET    /v1/spaces?id=<uuid>                             → detalle
 *   POST   /v1/spaces                                      → crea (body: outletId, sectorId?, name, seats?, shape?, sort?)
 *   POST   /v1/spaces?action=bulk                          → alta rápida (body: outletId, count, sectorId?)
 *   POST   /v1/spaces?action=layout                        → guarda layout batch (body: outletId, positions:[{tableId,posX,posY,width,height,rotation?,shape?}])
 *   PUT    /v1/spaces?id=<uuid>                             → actualiza
 *   DELETE /v1/spaces?id=<uuid>                             → deshabilita (status=0)
 *
 * Auth: panel + pos-app. Para pos-app, outletId sale del device ctx (no del
 * query/body) — mismo patrón que orders-core.php.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$outletId  = $ctx['outletId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;
$resource  = $_GET['resource'] ?? null;
$action    = $_GET['action'] ?? null;

$isPosApp    = ($ctx['realm'] ?? '') === 'pos-app';
$outletScope = $isPosApp ? $outletId : null;

global $db;
$svc = new \Punto\Api\Spaces\SpaceService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $table = $svc->find($companyId, (string) $id);
            if ($table === null) apiError('Espacio no encontrado', 404);
            if ($outletScope !== null && $table['outletId'] !== $outletScope) {
                apiError('Espacio no encontrado', 404);
            }
            apiOk($table);
            break;
        }

        $reqOutletId = $outletScope ?? (string) ($_GET['outletId'] ?? '');
        if ($reqOutletId === '') apiError('outletId requerido', 422);

        if ($resource === 'state') {
            apiOk(['spaces' => $svc->listWithState($companyId, $reqOutletId)]);
            break;
        }

        $sectorId = isset($_GET['sectorId']) ? (string) $_GET['sectorId'] : null;
        apiOk(['spaces' => $svc->list($companyId, $reqOutletId, $sectorId)]);
        break;

    case 'POST':
        if ($action === 'bulk') {
            try {
                $count    = (int) ($_POST['count'] ?? 0);
                $sectorId = !empty($_POST['sectorId']) ? (string) $_POST['sectorId'] : null;
                $bodyOutletId = (string) ($_POST['outletId'] ?? '');
                $ids = $svc->bulkCreate($companyId, $bodyOutletId, $count, $sectorId, $outletScope);
                apiOk(['spaceIds' => $ids], 201);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($action === 'layout') {
            try {
                $reqOutletId = $outletScope ?? (string) ($_POST['outletId'] ?? '');
                if ($reqOutletId === '') apiError('outletId requerido', 422);
                $positions = (array) ($_POST['positions'] ?? []);
                $svc->saveLayout($companyId, $reqOutletId, $positions);
                apiOk(['spaces' => $svc->listWithState($companyId, $reqOutletId)]);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        try {
            $newId = $svc->create($companyId, $_POST, $outletScope);
            apiOk($svc->find($companyId, $newId), 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if ($id === null) apiError('id requerido', 422);
        if ($outletScope !== null) {
            $existing = $svc->find($companyId, (string) $id);
            if ($existing === null || $existing['outletId'] !== $outletScope) {
                apiError('Espacio no encontrado', 404);
            }
        }
        try {
            apiOk($svc->update($companyId, (string) $id, $_POST));
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'DELETE':
        if ($id === null) apiError('id requerido', 422);
        if ($outletScope !== null) {
            $existing = $svc->find($companyId, (string) $id);
            if ($existing === null || $existing['outletId'] !== $outletScope) {
                apiError('Espacio no encontrado', 404);
            }
        }
        try {
            $svc->delete($companyId, (string) $id);
            apiOk(['deleted' => true]);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
