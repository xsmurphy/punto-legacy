<?php
/**
 * REST canónico (API compartida /api) — Caja (register) activa del POS.
 *
 *   POST /v1/active-register { registerId: "<uuid>" }
 *       → { ok: true, data: { registerId, registerName, expiresIn } }
 *       → cookie `_jwt_panel` HttpOnly reseteada con nuevo claim `rid`
 *
 * Análogo a `active-outlet.php` pero para la CAJA. El POS fusionado en
 * panel-next usa la sesión del panel (`_jwt_panel`), que arranca con
 * `rid=''`. Al elegir una caja, este endpoint re-emite el JWT con el
 * `registerId` en el claim `rid` — que `SaleService` lee como REGISTER_ID
 * para la numeración/asociación de la venta. El `oid` (outlet) se preserva.
 *
 * Pre-condición: la caja pedida debe pertenecer al tenant (companyId) Y a la
 * sucursal activa (outletId del JWT) Y estar activa (registerStatus=TRUE).
 *
 * Auth: realm `panel` (apiAuthTenant(['panel'])).
 *
 * Seguridad (mismas notas que active-outlet):
 *  - JWT re-emitido con TTL fresco (extensión leve de sesión intencional).
 *  - Cookie `SameSite=Lax` (bloquea POST cross-site del browser). Sin CSRF
 *    token explícito — aceptable para el modelo de amenaza actual.
 *  - La caja se valida contra companyId + outletId del JWT (no se confía en
 *    el body para el scope): una caja de otro tenant/sucursal → 404.
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$ctx = apiAuthTenant(['panel']);

// Body JSON → $_POST (mismo patrón que active-outlet / login).
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $_POST = array_merge($_POST, $json);
    }
}

$registerId = trim((string) ($_POST['registerId'] ?? ''));
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, $registerId)) {
    apiError('registerId inválido', 422);
}

// La caja debe pertenecer al tenant + sucursal activa (OUTLET_ID del JWT) y
// estar activa. Validar el scope acá; NO confiar en el body.
$row = ncmExecute(
    'SELECT registerId, registerName
       FROM register
      WHERE registerId = ? AND companyId = ? AND outletId = ? AND registerStatus = TRUE
      LIMIT 1',
    [$registerId, COMPANY_ID, OUTLET_ID]
);
if (!$row) {
    apiError('Caja no encontrada o inactiva en esta sucursal', 404);
}

// Re-emite el JWT panel preservando el outlet actual (OUTLET_ID) y seteando
// el `rid` = caja elegida. issueJwt(user, outletOverride, registerOverride).
$jwt = \Punto\Api\Auth\PanelAuth::issueJwt([
    'contactId' => $ctx['userId'],
    'companyId' => COMPANY_ID,
    'role'      => $ctx['roleId'],
], OUTLET_ID, (string) $row['registerId']);

apiOk([
    'registerId'   => (string) $row['registerId'],
    'registerName' => (string) $row['registerName'],
    'expiresIn'    => $jwt['expiresIn'],
]);
