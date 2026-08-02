<?php

/**
 * /api/v1/admin/modules.php — catálogo comercial de módulos (realm /admin).
 *
 * Gateado por adminMiddleware(). NO apiMiddleware.
 *
 * GET                          → catálogo completo: keys reales de
 *                                 ModulesService::nativeKeys() + metadata
 *                                 comercial (price/visibility/killswitch).
 * POST body {key, price?, visibility?, killswitch?} → upsert de un módulo.
 *      El kill-switch apaga el módulo para TODOS los tenants (enforcement
 *      real en ModulesService::list(), ver docblock ahí) sin tocar el
 *      estado por-tenant.
 *
 * Ver context/34-admin-saas-plan.md F4.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/ModuleAdminService.php';

adminMiddleware();

$svc    = new ModuleAdminService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    apiOk(['rows' => $svc->list()]);
}

if ($method === 'POST') {
    $body  = (string) file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        apiError('Body JSON inválido', 400);
    }

    $key = trim((string) ($input['key'] ?? ''));
    if ($key === '') {
        apiError('key es requerido', 422);
    }

    $result = $svc->update($key, $input);
    if (!$result['ok']) {
        apiError($result['error'] ?? 'error', $result['code'] ?? 422);
    }

    $killswitchChanged = array_key_exists('killswitch', $input);
    adminAudit(
        $killswitchChanged ? 'toggleModuleKillswitch' : 'updateModuleCatalog',
        'module',
        $key,
        null,
        $result['module'] ?? []
    );
    apiOk($result);
}

apiError('Método no permitido', 405);
