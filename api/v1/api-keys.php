<?php
/**
 * GET    /v1/api-keys                  -- Keys MCP del tenant (activas).
 * GET    /v1/api-keys?showRevoked=1    -- Incluye revocadas y vencidas (historial).
 * POST   /v1/api-keys { name, ttlDays? } -- Emite una. Devuelve el token UNA SOLA VEZ.
 * DELETE /v1/api-keys?id=X             -- Revoca (status=0). Preserva auditoría.
 *
 * M0 de `context/58`. Auth: realm `panel` — las keys se administran desde el
 * panel por una persona; el MCP mismo NUNCA puede emitir ni revocar keys (no
 * está en el allowlist de este endpoint), así que una key filtrada no puede
 * fabricarse más keys ni taparse revocándose las anteriores.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/ApiKeyService.php';

use Punto\Api\Auth\ApiKeyService;

$__ctx  = apiAuthTenant(['panel']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Gate: administrar keys es dar acceso programático a todo lo que el usuario
// puede leer, así que se pide el mismo permiso que cambiar la configuración del
// negocio — no un permiso de lectura. Mismo criterio que `devices.php`: una
// clave para todo el endpoint, porque listar ya es material sensible (dice
// cuántas integraciones hay y cuándo se usaron por última vez).
if (!hasPermission('settings.company.edit')) {
    apiError('No tenés permiso para esta acción (requiere: settings.company.edit)', 403);
}

$svc = new ApiKeyService();

if ($method === 'GET') {
    $showRevoked = ($_GET['showRevoked'] ?? '') === '1';
    apiOk(['keys' => $svc->listForCompany((string) COMPANY_ID, $showRevoked)]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($body)) {
        apiError('Body inválido', 400);
    }
    $name    = (string) ($body['name'] ?? '');
    $ttlDays = isset($body['ttlDays']) && is_numeric($body['ttlDays']) ? (int) $body['ttlDays'] : null;

    try {
        $res = $svc->issue([
            'companyId' => (string) COMPANY_ID,
            'userId'    => (string) USER_ID,
            'outletId'  => (string) OUTLET_ID,
            'roleId'    => defined('ROLE_ID') ? (string) ROLE_ID : '',
        ], $name, $ttlDays);
    } catch (\InvalidArgumentException $e) {
        apiError($e->getMessage(), 422);
    }

    // `token` viaja UNA sola vez y no vuelve a existir: en la BD queda su
    // sha256. Si el usuario lo pierde, revoca y emite otra — que es lo que
    // corresponde, no un endpoint que lo muestre de nuevo.
    apiOk($res, 201);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    if (!$svc->revoke($id, (string) COMPANY_ID, (string) USER_ID)) {
        // 404 tanto si no existe como si es de otro tenante o ya estaba
        // revocada: la revocación es idempotente hacia afuera y no confirma la
        // existencia de nada ajeno.
        apiError('Key no encontrada', 404);
    }
    apiOk(['revoked' => true]);
}

apiError('Método no permitido', 405);
