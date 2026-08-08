<?php
/**
 * REST canónico — Impuestos.
 *
 *   GET    /v1/taxes              → lista del tenant
 *   GET    /v1/taxes?id=<uuid>    → detalle
 *   POST   /v1/taxes              → crea  (body: { name, rate?, kind?, extra? })
 *   PUT    /v1/taxes?id=<uuid>    → actualiza (partial)
 *   DELETE /v1/taxes?id=<uuid>    → elimina
 *
 * rate/kind son opcionales: si no vienen se derivan de `name` (primer
 * número → kind='rate'; sin número → kind='exempt', rate=0). Ver
 * TaxService::resolveRateKind().
 *
 * Auth: panel (admin del catálogo). POS sigue leyendo de `taxonomy` con
 * sync automático vía trigger PG bidireccional — facturación electrónica
 * NO se afecta porque getTaxValue() lee taxonomyName que sigue siendo
 * sincronizado.
 *
 * F0 del plan de impuestos multi-país (context/38). Tabla `tax`
 * (migration 23 + 120).
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\Taxes\TaxService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $tax = $svc->find($companyId, (string) $id);
            if ($tax === null) apiError('Impuesto no encontrado', 404);
            apiOk($tax);
        }
        apiOk(['taxes' => $svc->list($companyId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            $tax   = $svc->find($companyId, $newId);
            apiOk($tax, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $tax = $svc->find($companyId, (string) $id);
            apiOk($tax);
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
