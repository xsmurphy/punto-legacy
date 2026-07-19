<?php
/**
 * /api/v1/space-sectors.php — CRUD de sectores del local (space_sector,
 * mig 80, context/15-espacios-module-plan.md F0+F1).
 *
 *   GET    /v1/space-sectors?outletId=<uuid>   → lista (panel + pos-app: el editor de layout y el plano operativo necesitan sectores)
 *   GET    /v1/space-sectors?id=<uuid>          → detalle
 *   POST   /v1/space-sectors                    → crea (body: outletId, name, sort?) — panel
 *   PUT    /v1/space-sectors?id=<uuid>           → actualiza — panel
 *   DELETE /v1/space-sectors?id=<uuid>           → soft-delete (status=0) — panel
 *
 * Auth: GET admite panel + pos-app; escritura exige panel.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctx       = apiAuthTenant($method === 'GET' ? ['panel', 'pos-app'] : ['panel']);
$companyId = $ctx['companyId'];
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\Spaces\SpaceSectorService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $sector = $svc->find($companyId, (string) $id);
            if ($sector === null) apiError('Sector no encontrado', 404);
            apiOk($sector);
            break;
        }
        $outletId = (string) ($_GET['outletId'] ?? ($ctx['outletId'] ?? ''));
        if ($outletId === '') apiError('outletId requerido', 422);
        apiOk(['sectors' => $svc->list($companyId, $outletId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            apiOk($svc->find($companyId, $newId), 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if ($id === null) apiError('id requerido', 422);
        try {
            apiOk($svc->update($companyId, (string) $id, $_POST));
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'DELETE':
        if ($id === null) apiError('id requerido', 422);
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
