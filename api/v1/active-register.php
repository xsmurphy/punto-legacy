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

// Actualizar la fila device con la caja (y sucursal si cambió).
ncmExecute(
    'UPDATE device SET registerid = ?::uuid, outletid = ?::uuid WHERE deviceid = ?::uuid AND companyid = ?::uuid',
    [(string) $row['registerId'], $targetOutletId, $deviceId, COMPANY_ID]
);

apiOk([
    'registerId'   => (string) $row['registerId'],
    'registerName' => (string) $row['registerName'],
]);
