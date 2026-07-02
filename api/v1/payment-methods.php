<?php
/**
 * REST canónico — Medios de pago del tenant.
 *
 *   GET    /v1/payment-methods            → lista del tenant (auto-seed si vacío)
 *   GET    /v1/payment-methods?id=<uuid>  → detalle
 *   POST   /v1/payment-methods            → crea  (body: { name, code?, hasChange?,
 *                                            requiresIdentifier?, identifierLabel?,
 *                                            identifierPlaceholder?, accountId? })
 *   PUT    /v1/payment-methods?id=<uuid>  → actualiza (partial)
 *   DELETE /v1/payment-methods?id=<uuid>  → elimina (Efectivo no borrable)
 *
 * Auth: panel. Identidad estable: el taxonomyId (UUID) es la clave del método
 * que las ventas nuevas guardan y que finAccountMap referencia. El BFF del POS
 * (`/api/pos/bootstrap`) consume este endpoint para armar paymentMethods.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\PaymentMethods\PaymentMethodService($db);

// bootstrap.php ya normaliza el body JSON/form-encoded → $_POST para todos los
// verbos, igual que categories.php.

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $pm = $svc->find($companyId, (string) $id);
            if ($pm === null) apiError('Medio de pago no encontrado', 404);
            apiOk($pm);
        }
        apiOk(['paymentMethods' => $svc->list($companyId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            $pm    = $svc->find($companyId, $newId);
            apiOk($pm, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $pm = $svc->find($companyId, (string) $id);
            apiOk($pm);
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
