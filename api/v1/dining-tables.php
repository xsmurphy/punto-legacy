<?php
/**
 * /api/v1/dining-tables.php — CRUD de mesas + editor de layout (dining_table,
 * mig 80, context/15-mesas-module-plan.md F0+F1). Nombre nuevo a propósito —
 * NO pisa el legacy `api/v1/tables.php` si existiera.
 *
 *   GET    /v1/dining-tables?outletId=<uuid>                     → lista simple (sectorId? filtra)
 *   GET    /v1/dining-tables?outletId=<uuid>&resource=state       → plano operativo (listWithState)
 *   GET    /v1/dining-tables?id=<uuid>                             → detalle
 *   POST   /v1/dining-tables                                      → crea (body: outletId, sectorId?, name, seats?, shape?, sort?)
 *   POST   /v1/dining-tables?action=bulk                          → alta rápida (body: outletId, count, sectorId?)
 *   POST   /v1/dining-tables?action=layout                        → guarda layout batch (body: outletId, positions:[{tableId,posX,posY,width,height,rotation?,shape?}])
 *   PUT    /v1/dining-tables?id=<uuid>                             → actualiza
 *   DELETE /v1/dining-tables?id=<uuid>                             → deshabilita (status=0)
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
$svc = new \Punto\Api\Tables\DiningTableService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $table = $svc->find($companyId, (string) $id);
            if ($table === null) apiError('Mesa no encontrada', 404);
            if ($outletScope !== null && $table['outletId'] !== $outletScope) {
                apiError('Mesa no encontrada', 404);
            }
            apiOk($table);
            break;
        }

        $reqOutletId = $outletScope ?? (string) ($_GET['outletId'] ?? '');
        if ($reqOutletId === '') apiError('outletId requerido', 422);

        if ($resource === 'state') {
            apiOk(['tables' => $svc->listWithState($companyId, $reqOutletId)]);
            break;
        }

        $sectorId = isset($_GET['sectorId']) ? (string) $_GET['sectorId'] : null;
        apiOk(['tables' => $svc->list($companyId, $reqOutletId, $sectorId)]);
        break;

    case 'POST':
        if ($action === 'bulk') {
            try {
                $count    = (int) ($_POST['count'] ?? 0);
                $sectorId = !empty($_POST['sectorId']) ? (string) $_POST['sectorId'] : null;
                $reqOutletId = $outletScope ?? (string) ($_POST['outletId'] ?? '');
                if ($reqOutletId === '') apiError('outletId requerido', 422);
                $ids = $svc->bulkCreate($companyId, $reqOutletId, $count, $sectorId);
                apiOk(['tableIds' => $ids], 201);
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
                apiOk(['tables' => $svc->listWithState($companyId, $reqOutletId)]);
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        try {
            $data = $_POST;
            if ($isPosApp) {
                $data['outletId'] = $outletId;
            }
            $newId = $svc->create($companyId, $data);
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
                apiError('Mesa no encontrada', 404);
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
                apiError('Mesa no encontrada', 404);
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
