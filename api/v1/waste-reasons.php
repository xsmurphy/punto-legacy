<?php
/**
 * REST canónico — Motivos de merma del tenant.
 *
 *   GET    /v1/waste-reasons            → lista del tenant (auto-seed si vacío)
 *   GET    /v1/waste-reasons?id=<uuid>  → detalle
 *   POST   /v1/waste-reasons            → crea  (body: { name, sortOrder? })
 *   PUT    /v1/waste-reasons?id=<uuid>  → actualiza (partial)
 *   DELETE /v1/waste-reasons?id=<uuid>  → elimina
 *
 * Auth: panel. Escritura gateada por production.manage (mismo permiso que
 * gatea producción y merma manual — ver context/23 F1).
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\Waste\WasteReasonService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $reason = $svc->find($companyId, (string) $id);
            if ($reason === null) apiError('Motivo de merma no encontrado', 404);
            apiOk($reason);
        }
        apiOk(['wasteReasons' => $svc->list($companyId)]);
        break;

    case 'POST':
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }
        try {
            $newId  = $svc->create($companyId, $_POST);
            $reason = $svc->find($companyId, $newId);
            apiOk($reason, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $reason = $svc->find($companyId, (string) $id);
            apiOk($reason);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'DELETE':
        if (!hasPermission('production.manage')) {
            apiError('No tenés permiso para esta acción (requiere: production.manage)', 403);
        }
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
