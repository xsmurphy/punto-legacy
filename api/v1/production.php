<?php
/**
 * REST — Órdenes de producción (F1, context/23-production-module-plan.md).
 *
 *   GET  /v1/production                              → lista (filtros: status, outletId, from, to, q)
 *   GET  /v1/production?id=<uuid>                     → detalle
 *   GET  /v1/production?resource=capacity&itemId=<uuid>&outletId=<uuid> → capacidad de producción
 *   POST /v1/production                               → crea (body: itemId, outletId, qtyPlanned,
 *                                                        locationId?, outputLocationId?, mode?
 *                                                        'draft'|'immediate', note?; si mode=immediate
 *                                                        también qtyProduced, wasteUnits?, wasteReasonId?,
 *                                                        ingredientAdjustments?)
 *   POST /v1/production?id=<uuid>&action=start        → draft → in_progress
 *   POST /v1/production?id=<uuid>&action=complete      → completa (body: qtyProduced, wasteUnits?,
 *                                                        wasteReasonId?, ingredientAdjustments?)
 *   POST /v1/production?id=<uuid>&action=cancel        → cancela (solo draft/in_progress)
 *
 * Auth: panel. Escritura (POST) gateada por production.manage.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;
$resource  = $_GET['resource'] ?? null;
$action    = $_GET['action'] ?? null;

global $db;
$svc = new \Punto\Api\Production\ProductionService($db);

switch ($method) {
    case 'GET':
        if ($resource === 'capacity') {
            $itemId   = (string) ($_GET['itemId'] ?? '');
            $outletId = (string) ($_GET['outletId'] ?? ($ctx['outletId'] ?? ''));
            if ($itemId === '' || $outletId === '') {
                apiError('itemId y outletId son requeridos', 422);
            }
            try {
                apiOk($svc->capacity($companyId, $itemId, $outletId));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }
        if ($id !== null) {
            $order = $svc->find($companyId, (string) $id);
            if ($order === null) apiError('Orden de producción no encontrada', 404);
            apiOk($order);
            break;
        }
        $filters = [
            'status'   => $_GET['status'] ?? null,
            'outletId' => $_GET['outletId'] ?? null,
            'from'     => $_GET['from'] ?? null,
            'to'       => $_GET['to'] ?? null,
            'q'        => $_GET['q'] ?? null,
        ];
        apiOk(['orders' => $svc->list($companyId, array_filter($filters, static fn ($v) => $v !== null && $v !== ''))]);
        break;

    case 'POST':
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }

        if ($id !== null && $action === 'start') {
            try {
                $svc->start($companyId, (string) $id);
                apiOk($svc->find($companyId, (string) $id));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'complete') {
            try {
                apiOk($svc->complete($companyId, $userId, (string) $id, $_POST));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null && $action === 'cancel') {
            try {
                $svc->cancel($companyId, (string) $id);
                apiOk($svc->find($companyId, (string) $id));
            } catch (\Throwable $e) {
                apiError($e->getMessage(), 422);
            }
            break;
        }

        if ($id !== null) {
            apiError('action inválida (esperado: start|complete|cancel)', 422);
        }

        try {
            $newId = $svc->create($companyId, $userId, $_POST);
            apiOk($svc->find($companyId, $newId), 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
