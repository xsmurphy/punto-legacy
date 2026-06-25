<?php
/**
 * POST /v1/auth/pair-pos-device -- Pairing de dispositivo POS.
 *
 * El admin re-confirma su contrasena y elige outlet+caja. Emite cookie `_jwt`
 * (device, 10 anyos) que el front almacena en el browser del dispositivo.
 * El admin NO pierde su `_jwt_panel` (panel, 24h) -- los dos cookies coexisten.
 *
 * Auth: solo admin logueado en panel (_jwt_panel).
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use Punto\Api\Auth\PanelAuth;
use Punto\Api\Auth\DeviceAuth;

apiAuthTenant(['panel']);

if (!hasPermission('settings.device.pair')) {
    apiError('No tenés permiso para esta acción (requiere: settings.device.pair)', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Solo POST soportado', 405);
}

$password   = trim((string) ($_POST['password']   ?? ''));
$outletId   = trim((string) ($_POST['outletId']   ?? ''));
$registerId = trim((string) ($_POST['registerId'] ?? ''));
$deviceName = trim((string) ($_POST['deviceName'] ?? ''));

if ($password === '' || $outletId === '' || $registerId === '') {
    apiError('password, outletId y registerId son requeridos', 422);
}

// Obtener el admin -- necesitamos salt y contactPassword para verificar
$admin = ncmExecute(
    'SELECT contactid, contactpassword, salt FROM contact WHERE contactid = ?::uuid AND companyid = ?::uuid AND contactstatus = 1 AND type = 0',
    [USER_ID, COMPANY_ID]
);
if (!$admin) {
    apiError('Usuario no encontrado', 401);
}

// Verificar contrasena con el mismo hash que el login del panel
$computedHash = PanelAuth::checkPassword($password, (string) ($admin['salt'] ?? ''));
$storedHash   = rtrim((string) ($admin['contactpassword'] ?? ''));
if (!hash_equals($storedHash, $computedHash)) {
    apiError('Contrasena incorrecta', 401);
}

// Validar outlet pertenece al tenant
$outlet = ncmExecute(
    'SELECT outletid FROM outlet WHERE outletid = ?::uuid AND companyid = ?::uuid AND outletstatus = 1',
    [$outletId, COMPANY_ID]
);
if (!$outlet) {
    apiError('Sucursal invalida o no activa', 422);
}

// Validar register pertenece a outlet y tenant
$register = ncmExecute(
    'SELECT registerid FROM register WHERE registerid = ?::uuid AND outletid = ?::uuid AND companyid = ?::uuid',
    [$registerId, $outletId, COMPANY_ID]
);
if (!$register) {
    apiError('Caja invalida o no pertenece a la sucursal', 422);
}

$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
$result    = DeviceAuth::issueJwt(
    COMPANY_ID,
    $outletId,
    $registerId,
    USER_ID,
    $deviceName !== '' ? $deviceName : null,
    $userAgent,
);

apiOk([
    'deviceId'  => $result['deviceId'],
    'pairedAt'  => date('c'),
    'expiresIn' => $result['expiresIn'],
]);
