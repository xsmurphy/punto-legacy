<?php
/**
 * REST canónico (API compartida /api) — Facturas Recurrentes (raw).
 *
 *   GET  /v1/reports/recurring                                   → { rows: [...] } crudo.
 *   POST /v1/reports/recurring (action=pause|activate|remove&id=<uuid>) → muta una recurrencia.
 *
 * Auth: realm `panel`. Tenant por companyId (la tabla no tiene outletId → sin ROC).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Reports\RecurringService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

if ($method === 'POST') {
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['pause', 'activate', 'remove'], true)) {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    if (!$svc->mutate($action, $id, COMPANY_ID)) {
        apiError('No se pudo procesar la acción', 500);
    }
    apiOk(['id' => $id, 'action' => $action]);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

apiOk(['rows' => $svc->listAll(COMPANY_ID)]);
