<?php
/**
 * POST /v1/auth/unpair-pos-device -- Desparea un dispositivo POS.
 *
 * Acepta tanto admin (panel) como el propio device. Si el caller es el
 * propio device que se esta desvinculando, clearea la cookie `_jwt`.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Punto\Api\Auth\DeviceAuth;

// Cargar el helper de pos context (acepta device JWT o panel JWT)
require_once dirname(__DIR__, 2) . '/lib/Auth/apiAuthPosContext.php';

$ctx = apiAuthPosContext();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Solo POST soportado', 405);
}

$deviceId = trim((string) ($_POST['deviceId'] ?? ''));
if ($deviceId === '') {
    apiError('deviceId requerido', 422);
}

// Validar que el device pertenece al companyId del caller
$device = ncmExecute(
    'SELECT "deviceId", "companyId" FROM device WHERE "deviceId" = ?::uuid',
    [$deviceId]
);
if (!$device) {
    apiError('Device no encontrado', 404);
}
$devCompanyId = (string) ($device['companyid'] ?? $device['companyId'] ?? '');
if ($devCompanyId !== COMPANY_ID) {
    apiError('No autorizado', 403);
}

// Si el caller es el propio device desvinculandose: clearear cookie
if (($ctx['isDevice'] ?? false) === true && ($ctx['deviceId'] ?? '') === $deviceId) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $cookieOpts = [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ];
    $cookieDomain = $_ENV['COOKIE_DOMAIN'] ?? '';
    if ($cookieDomain !== '') {
        $cookieOpts['domain'] = $cookieDomain;
    }
    setcookie('_jwt', '', $cookieOpts);
}

DeviceAuth::revoke($deviceId, COMPANY_ID);

apiOk(['ok' => true]);
