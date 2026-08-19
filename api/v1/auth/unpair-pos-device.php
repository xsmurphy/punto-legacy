<?php
/**
 * POST /v1/auth/unpair-pos-device -- Auto-desparea: un device se desvincula a sí mismo.
 *
 * Acepta el Bearer token del device (Authorization: Bearer <token>) via apiAuthPosContext().
 * El device solo puede revocarse a sí mismo.
 * Los admins del panel usan DELETE /v1/devices?id=X para revocar cualquier device.
 *
 * El cliente (frontend/revoke-this-device/route.ts) llama clearDeviceToken() para
 * limpiar el localStorage tras la respuesta exitosa.
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/lib/services/RegisterLeaseService.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Services\RegisterLeaseService;

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
    'SELECT deviceid, companyid FROM device WHERE deviceid = ?::uuid',
    [$deviceId]
);
if (!$device) {
    apiError('Device no encontrado', 404);
}
$devCompanyId = (string) ($device['companyid'] ?? '');
if ($devCompanyId !== COMPANY_ID) {
    apiError('No autorizado', 403);
}

// Si el caller es un device (no panel admin), solo puede revocarse a sí mismo
if (!empty($ctx['isDevice']) && $ctx['isDevice'] === true) {
    if (($ctx['deviceId'] ?? '') !== $deviceId) {
        apiError('Un dispositivo solo puede revocarse a sí mismo', 403);
    }
}

// El token está en localStorage del device; el cliente llama clearDeviceToken()
// tras recibir la respuesta exitosa. No hay cookie que limpiar en el servidor.
DeviceAuth::revoke($deviceId, COMPANY_ID);

// Auto-desparear sin liberar la tenencia de caja (context/29 §4) dejaba
// `register_lease` tomada para siempre: el device que se acaba de revocar a sí
// mismo ya no puede llamar a claim.php de nuevo para soltarla (bug real
// 2026-08-19). 'released': es el propio device liberándose, no un admin
// desconectando a un tercero — mismo espíritu que cerrar caja.
RegisterLeaseService::releaseByDevice($deviceId, COMPANY_ID, 'device:' . $deviceId, 'released');

apiOk(['ok' => true]);
