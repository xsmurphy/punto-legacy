<?php
/**
 * /api/v1/device_invitations.php -- Device Authorization Grant (RFC 8628 simplificado).
 *
 * Endpoints publicos (sin auth tenant):
 *   POST ?resource=open&id={uuid}   -- el dispositivo abre la invitacion y recibe el userCode
 *   GET  ?resource=status&id={uuid} -- polling del dispositivo hasta approved/denied/consumed/expired
 *
 * Ambos publicos exigen el SECRETO DE PAIRING (mig 171) salvo en la primerisima
 * apertura, que es justamente la que lo emite. Viaja en el body form-encoded
 * (`pairingSecret`) en el POST de open, y en el header `X-Pairing-Secret` en el
 * GET de status -- NUNCA en la query string: es una credencial y la query
 * string queda en logs de proxy, historial y Referer.
 *
 * Endpoints autenticados (realm panel):
 *   POST action=create   -- genera una nueva invitacion (admin)
 *   POST action=approve  -- aprueba una invitacion con userCode (admin)
 *   POST action=deny     -- deniega una invitacion (admin)
 *   POST action=cancel   -- alias de deny
 *   GET  ?resource=list  -- lista invitaciones activas del tenant (admin)
 *
 * TODO: agregar rate limiting en open/status para mitigar polling abusivo.
 * TODO: registrar geo IP en open (device_geo) cuando haya servicio disponible.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/Auth/DeviceAuth.php';
require_once __DIR__ . '/../lib/services/DeviceInvitationService.php';

use Punto\Api\Services\DeviceInvitationService;

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');
$id       = (string) ($_GET['id'] ?? '');
$UUID_RE  = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$svc = new DeviceInvitationService();

// --- Endpoints publicos (sin auth tenant) ---

if ($method === 'POST' && $resource === 'open') {
    if ($id === '' || !preg_match($UUID_RE, $id)) {
        apiError('id invalido', 422);
    }
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    // Tomar solo la primera IP si hay cadena de proxies
    $ip = trim(explode(',', $ip)[0]);
    // Secreto de la sesion de pairing. Ausente = primera apertura.
    $pairingSecret = trim((string) ($_POST['pairingSecret'] ?? '')) ?: null;

    try {
        $result = $svc->open($id, $ua, $ip, $pairingSecret);
        apiOk($result);
    } catch (\RuntimeException $e) {
        $code = $e->getCode();
        apiError($e->getMessage(), in_array($code, [404, 410, 409, 422], true) ? $code : 422);
    }
}

if ($method === 'GET' && $resource === 'status') {
    if ($id === '' || !preg_match($UUID_RE, $id)) {
        apiError('id invalido', 422);
    }
    // En header, no en query string (ver el docblock de arriba).
    $pairingSecret = trim((string) ($_SERVER['HTTP_X_PAIRING_SECRET'] ?? '')) ?: null;

    try {
        $result = $svc->status($id, $pairingSecret);
        // El $result incluye `token` cuando status=approved.
        // El client (connect-view.tsx) lee el token del body y lo persiste en localStorage.
        apiOk($result);
    } catch (\RuntimeException $e) {
        $code = $e->getCode();
        apiError($e->getMessage(), in_array($code, [404, 410, 409, 422], true) ? $code : 422);
    }
}

// --- Endpoints autenticados (realm panel) ---

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];

// Gate de autorización. Aprobar una invitación emite un token de dispositivo
// ETERNO con acceso al tenant: es la operación más sensible de este archivo y
// no tiene vuelta atrás salvo revocando el device. Las ramas públicas
// (`open`/`status`, arriba) quedan fuera a propósito — las ejecuta el
// dispositivo que todavía no está pareado, su control es el userCode.
if (!hasPermission('settings.device.pair')) {
    apiError('No tenés permiso para esta acción (requiere: settings.device.pair)', 403);
}

// GET ?resource=list
if ($method === 'GET' && $resource === 'list') {
    apiOk(['invitations' => $svc->list($companyId)]);
}

// POST -- dispatcher por action
if ($method === 'POST') {
    $body   = $_POST;
    $action = (string) ($body['action'] ?? '');

    if ($action === 'create') {
        $module     = trim((string) ($body['module'] ?? ''));
        $outletId   = trim((string) ($body['outletId'] ?? '')) ?: null;
        $registerId = trim((string) ($body['registerId'] ?? '')) ?: null;
        $deviceName = trim((string) ($body['deviceName'] ?? '')) ?: null;
        $ttlHours   = max(1, min(168, (int) ($body['ttlHours'] ?? 24)));
        try {
            $result = $svc->create($companyId, $userId, $module, $outletId, $registerId, $deviceName, $ttlHours);
            apiOk($result);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            apiError($e->getMessage(), in_array($code, [404, 410, 409, 422, 403], true) ? $code : 422);
        }
    }

    if ($action === 'approve') {
        $invId           = trim((string) ($body['id'] ?? ''));
        $userCodeConfirm = trim((string) ($body['userCode'] ?? ''));
        if ($invId === '' || !preg_match($UUID_RE, $invId)) {
            apiError('id invalido', 422);
        }
        if ($userCodeConfirm === '') {
            apiError('userCode requerido', 422);
        }
        try {
            $result = $svc->approve($invId, $companyId, $userId, strtoupper($userCodeConfirm));
            apiOk($result);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            apiError($e->getMessage(), in_array($code, [404, 410, 409, 422, 403], true) ? $code : 422);
        }
    }

    if ($action === 'deny' || $action === 'cancel') {
        $invId = trim((string) ($body['id'] ?? ''));
        if ($invId === '' || !preg_match($UUID_RE, $invId)) {
            apiError('id invalido', 422);
        }
        try {
            $svc->deny($invId, $companyId);
            apiOk(['denied' => true]);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            apiError($e->getMessage(), in_array($code, [404, 410, 409, 422, 403], true) ? $code : 422);
        }
    }

    if ($action === 'reconnect') {
        $deviceId = trim((string)($body['deviceId'] ?? ''));
        if ($deviceId === '' || !preg_match($UUID_RE, $deviceId)) {
            apiError('deviceId invalido', 422);
        }
        try {
            $result = $svc->createReconnect($deviceId, $companyId, $userId);
            apiOk($result);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            apiError($e->getMessage(), in_array($code, [404, 410, 409, 422, 403], true) ? $code : 422);
        }
    }

    apiError('Accion invalida', 422);
}

apiError('Metodo no permitido', 405);
