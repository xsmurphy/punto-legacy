<?php
/**
 * REST canónico (API compartida /api) — Cambio de sucursal activa del realm `panel`.
 *
 *   POST /v1/active-outlet { outletId: "<uuid>" }
 *       → { ok: true, data: { outletId, outletName, expiresIn, token } }
 *       → RE-EMITE la sesión con el outlet nuevo. El `token` viaja en la respuesta
 *         y el cliente DEBE adoptarlo (context/54 F1): si sigue mandando el
 *         anterior, el backend resuelve la sucursal vieja.
 *
 * Replica el "selector de sucursal" del menú user del panel legacy. El
 * front (frontend sidebar) llama acá, el backend re-emite el JWT
 * panel con el nuevo `oid` y la cookie viaja en la response. La
 * próxima request a cualquier endpoint que filtre por outlet (reports,
 * stock, drawers) usa la nueva sucursal automáticamente.
 *
 * Pre-condición: el outletId pedido debe pertenecer al tenant del JWT
 * actual Y estar activo (outletStatus=1). No se acepta `oid=''` ("Todas
 * las sucursales") en este slice — apiAuthTenant auto-resuelve `oid=''`
 * al primer outlet, así que ese sentinel no funciona sin tocar cada
 * service downstream. Queda como follow-up.
 *
 * Auth: realm `panel` (apiAuthTenant(['panel'])).
 *
 * Decisiones de seguridad:
 *
 *  - El JWT re-emitido arranca con TTL fresco (`PANEL_JWT_TTL`), NO con el
 *    `exp` remanente del token anterior. Es una extensión leve de sesión
 *    intencional: el usuario está activo (cambió de sucursal), justifica
 *    refrescar el reloj. Si se quisiera preservar el `exp` original habría
 *    que decodear el JWT en mano (`jwtAuthenticate` no expone `AUTHED_EXP`).
 *
 *  - CSRF: ya no aplica el razonamiento de `SameSite` — desde context/54 F1 la
 *    credencial es un Bearer, no una cookie. Un sitio de terceros no puede
 *    hacer que el browser lo adjunte (no hay envío ambiental), así que la
 *    protección es más fuerte que antes y no depende de un atributo de cookie.
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$ctx = apiAuthTenant(['panel']);

// Body JSON. login.php parsea manualmente porque apiBootstrap solo normaliza
// JSON a $_POST para PUT/DELETE/PATCH — copiamos ese mismo patrón.
$raw = file_get_contents('php://input');
if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) {
        $_POST = array_merge($_POST, $json);
    }
}

$outletId = trim((string) ($_POST['outletId'] ?? ''));
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, $outletId)) {
    apiError('outletId inválido', 422);
}

// Pertenecer al tenant NO alcanza: esta es la puerta de ESCRITURA del alcance.
// El `oid` que se re-emite acá vuelve como `OUTLET_ID` en cada request, y
// `OUTLET_ID` es la sucursal a la que se imputan las VENTAS, los movimientos de
// caja y el stock. Sin este gate, un usuario asignado a 2 sucursales se paraba
// en una tercera y facturaba ahí — el filtro de LECTURA lo hubiera tapado
// (`bootstrap.php` repunta `$outletId` al conjunto), pero tapar no es cerrar, y
// el 403 es la respuesta honesta: la sucursal existe, no es suya.
if (!\Punto\Api\Outlets\OutletScope::allows(
    \Punto\Api\Outlets\OutletScope::current(),
    $outletId,
    (string) COMPANY_ID
)) {
    apiError('Tu usuario no tiene acceso a esa sucursal', 403);
}

$row = ncmExecute(
    'SELECT outletId, outletName FROM outlet WHERE outletId = ? AND companyId = ? AND outletStatus = 1 LIMIT 1',
    [$outletId, COMPANY_ID]
);
if (!$row) {
    apiError('Sucursal no encontrada o inactiva', 404);
}

// PanelAuth::issuePanelSession acepta override de outletId (NUEVO 2026-06-12) — saltea
// el SQL que resuelve "primer outlet activo" porque acá ya validamos pertenencia.
$jwt = \Punto\Api\Auth\PanelAuth::issuePanelSession([
    'contactId' => $ctx['userId'],
    'companyId' => COMPANY_ID,
    'role'      => $ctx['roleId'],
], (string) $row['outletId']);

// `token`: cambiar de sucursal RE-EMITE la sesión de panel (con el outlet
// nuevo), así que hay una credencial nueva y el cliente tiene que adoptarla. El
// panel es Bearer (context/54 F1): si el token se quedara solo en la cookie, el
// front seguiría mandando el ANTERIOR y el cambio de sucursal no tendría efecto
// — el panel diría "Sucursal B" mientras el backend sigue resolviendo A.
apiOk([
    'outletId'   => (string) $row['outletId'],
    'outletName' => (string) $row['outletName'],
    'expiresIn'  => $jwt['expiresIn'],
    'token'      => $jwt['token'],
]);
