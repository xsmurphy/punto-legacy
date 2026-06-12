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
 * Auth: panel (los templates solo se manejan desde admin).
 */

require_once __DIR__ . '/../bootstrap.php';

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = $_GET['id'] ?? null;

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
