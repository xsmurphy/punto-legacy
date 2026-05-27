<?php
/**
 * REST canónico — Facturas Recurrentes (motor ERP, raw).
 *
 *   GET  /API/v1/reports/recurring                               → { rows: [...] } crudo.
 *   POST /API/v1/reports/recurring (action=pause|activate|remove&id=<uuid>) → muta una recurrencia.
 *
 * Lectura sin formatear/HTML (el front mapea/formatea). Escritura scopeada por COMPANY_ID del
 * JWT. Auth: JWT. Tenant por companyId (la tabla no tiene outletId → sin $roc). Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportRecurringService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$svc    = new ReportRecurringService();

if ($method === 'POST') {
    // Permiso de escritura: bloquea el rol read-only (7) — misma convención que satisfaction.
    // El gate fino allowUser('sales','edit') requiere ROLE_ID (no definido por apiMiddleware,
    // que usa PANEL_AUTHED_ROLE); wirearlo es el follow-up que habilita allowUser en todo v1.
    if ((int) PANEL_AUTHED_ROLE === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if (!in_array($action, ['pause', 'activate', 'remove'], true)) {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
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

apiOk(['rows' => $svc->listAll(COMPANY_ID)]);
