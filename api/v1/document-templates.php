<?php
/**
 * REST canónico — Document Templates (builder de tickets/facturas/cotizaciones).
 *
 *   GET    /v1/document-templates              → lista del tenant
 *   GET    /v1/document-templates?id=<uuid>    → detalle
 *   POST   /v1/document-templates              → crea
 *   PUT    /v1/document-templates?id=<uuid>    → actualiza (partial)
 *   DELETE /v1/document-templates?id=<uuid>    → elimina
 *
 * Body POST/PUT (JSON):
 *   { name, docType, pageSize, isDefault?, config? }
 *
 * Auth: MULTI-REALM — `apiAuthTenant(['panel', 'pos-app'])`. La administración
 * (crear/editar/borrar) sigue siendo solo del panel; el realm `pos-app`
 * (token del dispositivo) se agregó para que el POS pueda LEER las plantillas
 * y bajarlas al bootstrap local (context/08 §53 — la impresión de un
 * comprobante ya emitido tiene que resolver contra el cache del device, no
 * pedirle la plantilla al server en el momento de imprimir — hueco P0
 * cerrado 2026-08-16, ver `frontend/app/api/pos/bootstrap/route.ts`). Mismo
 * patrón que items.php/item_addons.php: el device es GET-only.
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;

if (($ctx['realm'] ?? '') === 'pos-app' && $method !== 'GET') {
    apiError('El dispositivo POS solo puede leer las plantillas', 403);
}

global $db;
$svc = new \Punto\Api\Settings\DocumentTemplateService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $tpl = $svc->find($companyId, (string) $id);
            if ($tpl === null) apiError('Template no encontrado', 404);
            apiOk($tpl);
        }
        apiOk(['templates' => $svc->list($companyId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            $tpl   = $svc->find($companyId, $newId);
            apiOk($tpl, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $tpl = $svc->find($companyId, (string) $id);
            apiOk($tpl);
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
