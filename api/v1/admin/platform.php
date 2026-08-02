<?php

/**
 * /api/v1/admin/platform.php — config de negocio (llaves de terceros, flags)
 * y broadcast a tenants (realm /admin, F6 §3/§5, context/34-admin-saas-plan.md).
 *
 * Gateado por adminMiddleware() + adminRequireRole('owner') — TODO el archivo,
 * incl. GET (son llaves de terceros, aunque enmascaradas).
 *
 *   GET                              → { groups: [...] } (ver PlatformConfigAdminService::GROUPS)
 *   POST action=setGroup {key, fields:{...}} → guarda un grupo (secretos: solo
 *        pisa si el valor mandado no es la máscara ••••)
 *   POST action=broadcast {title, message, link?} → aviso global en `notify`
 *        (companyId NULL) — lo muestra el centro de notificaciones del panel.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/PlatformConfigAdminService.php';

adminMiddleware();
adminRequireRole('owner'); // llaves de terceros — owner-only, ver matriz F6

$svc    = new PlatformConfigAdminService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    apiOk(['groups' => $svc->listGroups()]);
}

if ($method === 'POST') {
    $body  = (string) file_get_contents('php://input');
    $input = json_decode($body, true);
    if (!is_array($input)) {
        apiError('Body JSON inválido', 400);
    }

    $action = trim((string) ($input['action'] ?? ''));

    if ($action === 'setGroup') {
        $key    = trim((string) ($input['key'] ?? ''));
        $fields = is_array($input['fields'] ?? null) ? $input['fields'] : [];
        if ($key === '') {
            apiError('key es requerido', 422);
        }
        $result = $svc->setGroup($key, $fields, ADMIN_AUTHED_ID);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        // Audit: solo qué campos cambiaron, nunca valores (pueden ser secretos).
        adminAudit('setPlatformConfig', 'platformConfig', $key, null, ['fields' => array_keys($fields)]);
        apiOk($result);
    }

    if ($action === 'broadcast') {
        $title   = (string) ($input['title'] ?? '');
        $message = (string) ($input['message'] ?? '');
        $link    = isset($input['link']) ? (string) $input['link'] : null;

        $result = $svc->broadcast($title, $message, $link);
        if (!$result['ok']) {
            apiError($result['error'] ?? 'error', $result['code'] ?? 422);
        }
        adminAudit('broadcastNotify', 'notify', (string) ($result['notifyId'] ?? ''), $title, ['message' => $message, 'link' => $link]);
        apiOk($result);
    }

    apiError('Acción no soportada', 422);
}

apiError('Método no permitido', 405);
