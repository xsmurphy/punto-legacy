<?php
/**
 * SSO handoff: recibe un JWT del POS emitido por el panel y lo setea como cookie
 * HttpOnly en el dominio del app (app.punto.la). Después redirige a /.
 *
 * Por qué existe:
 *   - El link "Caja" del sidebar del panel apunta al app pero con subdominios
 *     separados (panel.punto.la / app.punto.la) las cookies no se comparten.
 *     Hasta el JWT, el panel pasaba ?i=<base64 de cid,oid> que solo seteaba
 *     localStorage — pero sin _jwt cookie, el primer XHR fallaba con 401.
 *
 *   - El JS no puede setear cookies HttpOnly desde el client. Por eso el handoff
 *     es server-side: panel emite token firmado → app lo recibe → cookie HttpOnly.
 *
 * Seguridad:
 *   - El token se valida con el mismo JWT_SECRET que login.php usa.
 *   - Verifica iss === 'pos-app' (separa realms).
 *   - TTL del token de handoff es corto (60s) y se chequea contra `exp`.
 *   - Si llega con cookie _jwt ya seteada, refrescamos también para evitar
 *     dejar al user en estado inconsistente.
 */

session_start();
require_once(__DIR__ . '/includes/cors.php');
require_once(__DIR__ . '/includes/jwt.php');
require_once(__DIR__ . '/includes/jwt_middleware.php');

$token = $_GET['t'] ?? '';
if ($token === '') {
    http_response_code(400);
    die('handoff: token requerido');
}

$secret = $_ENV['JWT_SECRET'] ?? '';
if ($secret === '') {
    http_response_code(500);
    die('handoff: JWT no configurado');
}

$payload = jwtDecode($token, $secret);
if (!is_array($payload)) {
    http_response_code(401);
    die('handoff: token inválido');
}

// Realm gate: solo tokens POS (`iss=pos-app`). Reusamos el contrato de jwt_middleware.
if (($payload['iss'] ?? '') !== 'pos-app') {
    http_response_code(401);
    die('handoff: realm incorrecto');
}

// El token de handoff es de uso corto — exigimos exp futuro.
if (!isset($payload['exp']) || $payload['exp'] < time()) {
    http_response_code(401);
    die('handoff: token expirado');
}

// P1 guard: rechazar tokens con identidad vacía (sub/cid/oid).
// Un panel con sesión rota podría firmar un token con strings vacíos y dejar
// al user "autenticado" en el POS sin company → datos vacíos en /fetchs.
$_sub = (string)($payload['sub'] ?? '');
$_cid = (string)($payload['cid'] ?? '');
$_oid = (string)($payload['oid'] ?? '');
if ($_sub === '' || $_cid === '' || $_oid === '') {
    http_response_code(401);
    die('handoff: token sin identidad completa');
}

// Reemitimos el cookie con TTL real del POS (no el corto del handoff).
// Esto evita que el user se quede con un cookie de 60s post-redirect.
$ttl = (int)($_ENV['JWT_TTL'] ?? 28800);
$now = time();
$newPayload = [
    'iss'  => 'pos-app',
    'sub'  => (string)($payload['sub'] ?? ''),
    'cid'  => (string)($payload['cid'] ?? ''),
    'oid'  => (string)($payload['oid'] ?? ''),
    'rid'  => (string)($payload['rid'] ?? ''),
    'role' => (int)($payload['role'] ?? 0),
    'iat'  => $now,
    'exp'  => $now + $ttl,
];
if (!empty($payload['did'])) {
    $newPayload['did'] = (string)$payload['did'];
}

$jwtToken = jwtEncode($newPayload, $secret);
jwtSetCookie($jwtToken, $ttl);

// localStorage del POS también necesita companyId+outletId+installation para
// que updateDB.all() arranque sin pedir nada. Lo hace el handler de `?i=` en
// index.html. Para preservar ese flow, redirigimos a / con `?i=<base64>` así
// el handler client-side persiste lo necesario.
$cid = (string)$newPayload['cid'];
$oid = (string)$newPayload['oid'];
$i   = base64_encode($cid . ',' . $oid);

header('Location: /?i=' . rawurlencode($i));
exit;
