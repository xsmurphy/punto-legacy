<?php
/**
 * /api/v1/order-stations.php — CRUD de estaciones de preparación
 * (order_station, mig 79, context/24-orders-module-plan.md).
 *
 *   GET    /v1/order-stations?outletId=<uuid>   → lista (panel + pos-app: el modo
 *                                                  orden de O1 necesita ver las
 *                                                  estaciones para rutear en el POS)
 *   GET    /v1/order-stations?id=<uuid>          → detalle
 *   POST   /v1/order-stations                    → crea (body: name, outletId?, categoryIds?, sort?) — panel
 *   PUT    /v1/order-stations?id=<uuid>           → actualiza — panel
 *   DELETE /v1/order-stations?id=<uuid>           → soft-delete (status=0) — panel
 *
 * Auth: GET admite panel + pos-app; escritura (POST/PUT/DELETE) exige panel.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctx    = apiAuthTenant($method === 'GET' ? ['panel', 'pos-app'] : ['panel']);
$companyId = $ctx['companyId'];
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\Orders\StationService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $station = $svc->find($companyId, (string) $id);
            if ($station === null) apiError('Estación no encontrada', 404);
            apiOk($station);
            break;
        }
        $outletId = isset($_GET['outletId']) ? (string) $_GET['outletId'] : ($ctx['outletId'] ?? null);
        apiOk(['stations' => $svc->list($companyId, $outletId !== '' ? $outletId : null)]);
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
