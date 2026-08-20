<?php
/**
 * REST canónico (API compartida /api) — Caja (register) activa del POS.
 *
 *   POST /v1/active-register { registerId: "<uuid>", outletId?: "<uuid>" }
 *       → { ok: true, data: { registerId, registerName } }
 *
 * Actualiza la fila `device` con la caja elegida (y opcionalmente la sucursal).
 * Ya NO re-emite el JWT — el contexto operativo se resuelve desde la fila device
 * en cada request (apiAuthTenant pos-app). Los tokens viejos con oid/rid siguen
 * funcionando: los claims se ignoran, el scope viene de la BD.
 *
 * Auth: realm `pos-app` (apiAuthTenant(['pos-app'])).
 *
 * Validaciones:
 *  - registerId debe ser UUID válido.
 *  - La caja debe pertenecer al tenant (companyId) y a la sucursal del device.
 *  - Si viene outletId en el body, se valida pertenencia antes del UPDATE.
 *  - registerStatus = TRUE.
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$ctx = apiAuthTenant(['pos-app']);

$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $_POST = array_merge($_POST, $json);
    }
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$registerId = trim((string) ($_POST['registerId'] ?? ''));
if (!preg_match($uuidRe, $registerId)) {
    apiError('registerId inválido', 422);
}

$deviceId  = defined('AUTHED_DEVICE_ID') ? AUTHED_DEVICE_ID : '';
if ($deviceId === '') {
    apiError('Device no identificado', 400);
}

// outletId opcional: si viene en el body, cambiar también la sucursal del device.
$newOutletId = trim((string) ($_POST['outletId'] ?? ''));
if ($newOutletId !== '' && !preg_match($uuidRe, $newOutletId)) {
    apiError('outletId inválido', 422);
}

// Resolver la sucursal activa del device (puede cambiar si newOutletId viene en body).
$targetOutletId = $ctx['outletId']; // ya viene de la fila device via apiAuthTenant

if ($newOutletId !== '') {
    // Validar que el outletId pedido pertenece al tenant.
    $outletRow = ncmExecute(
        'SELECT outletId FROM outlet WHERE outletId = ?::uuid AND companyId = ?::uuid AND outletStatus = 1 LIMIT 1',
        [$newOutletId, COMPANY_ID]
    );
    if (!$outletRow) {
        apiError('Sucursal no encontrada o inactiva', 404);
    }
    $targetOutletId = $newOutletId;
}

// Validar que la caja pertenece al tenant + sucursal objetivo + está activa.
$row = ncmExecute(
    'SELECT registerId, registerName
       FROM register
      WHERE registerId = ?::uuid AND companyId = ?::uuid AND outletId = ?::uuid AND registerStatus = TRUE
      LIMIT 1',
    [$registerId, COMPANY_ID, $targetOutletId]
);
if (!$row) {
    apiError('Caja no encontrada o inactiva en esta sucursal', 404);
}

// Liberar la tenencia (`register_lease`) que este device tenga en la caja
// VIEJA, si tiene alguna, ANTES de reasignarlo a la nueva — bug real
// verificado en prod 2026-08-20: un device tomaba "Caja Mariano", el owner
// lo reasignaba a "Nueva Caja" desde acá, y la tenencia anterior nunca se
// liberaba — "Caja Mariano" quedaba bloqueada para siempre por un device que
// ya no opera ahí (context/29-numeracion-y-exclusividad-de-caja.md §4,
// "dispositivo cambia de caja": "la caja anterior se libera igual que
// liberación normal"). Este es el ÚNICO lugar del código que hoy cambia
// `device.registerid` de un device EXISTENTE (ver RegisterLeaseService::
// releaseByDevice() docblock) — mismo wrapper que usan devices.php (revoke)
// y unpair-pos-device.php, self-contenido en su propia transacción/advisory
// lock por la caja VIEJA, así que se puede llamar acá sin abrir una TX
// propia. No-op silencioso si el device no tenía tenencia activa (el caso
// normal: la mayoría de los cambios de caja son de un device que nunca
// facturó, o que ya cerró caja antes de moverse).
require_once __DIR__ . '/../lib/services/RegisterLeaseService.php';
\Punto\Api\Services\RegisterLeaseService::releaseByDevice(
    $deviceId,
    COMPANY_ID,
    'device:register-change',
    'released'
);

// Actualizar la fila device con la caja (y sucursal si cambió).
$updated = ncmExecute(
    'UPDATE device SET registerid = ?::uuid, outletid = ?::uuid WHERE deviceid = ?::uuid AND companyid = ?::uuid',
    [(string) $row['registerId'], $targetOutletId, $deviceId, COMPANY_ID]
);
if ($updated === false) {
    apiError('No se pudo actualizar el dispositivo', 500);
}

apiOk([
    'registerId'   => (string) $row['registerId'],
    'registerName' => (string) $row['registerName'],
]);
