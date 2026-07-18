<?php
/**
 * REST — Merma manual (F1, context/23-production-module-plan.md).
 *
 *   GET  /v1/waste                → lista de eventos (filtros: from, to, reasonId, outletId)
 *   POST /v1/waste                → registra merma manual (body: itemId, qty, reasonId, outletId,
 *                                    locationId?, note?)
 *
 * Auth: panel. Escritura gateada por production.manage (misma gate que producción).
 * La merma generada automáticamente al completar una orden (source='production')
 * se registra desde ProductionService::complete, no desde este endpoint.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

global $db;
$svc = new \Punto\Api\Production\ProductionService($db);

switch ($method) {
    case 'GET':
        $filters = [
            'from'     => $_GET['from'] ?? null,
            'to'       => $_GET['to'] ?? null,
            'reasonId' => $_GET['reasonId'] ?? null,
            'outletId' => $_GET['outletId'] ?? null,
        ];
        apiOk(['wasteEvents' => $svc->listWaste($companyId, array_filter($filters, static fn ($v) => $v !== null && $v !== ''))]);
        break;

    case 'POST':
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }
        try {
            $wasteId = $svc->registerWaste($companyId, $userId, $_POST);
            apiOk(['id' => $wasteId], 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
