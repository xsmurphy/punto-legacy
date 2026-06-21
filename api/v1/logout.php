<?php
/**
 * POST /v1/logout — cierra la sesión del panel (cookie `_jwt_panel`).
 *
 * No requiere auth: si la cookie está expirada o ausente, igual responde 200
 * (idempotente — el efecto deseado es "cookie borrada", que ya estaría).
 *
 * NO toca `_jwt` (realm pos-app, device pairing). El POS mantiene su sesión
 * persistente — el operador puede cerrar la del panel sin desparear la caja
 * (ver [[project_pos_dual_session_model]]).
 *
 * No invalida el token server-side (no hay revocation list para `_jwt_panel`
 * y TTL es 24h corto — defense-in-depth: si alguien capturó el token antes
 * del logout, tiene 24h restantes para usarlo). Si esto deja de ser aceptable,
 * agregar tabla `panel_token_revoked(tokenHash, revokedAt)` y check en el
 * middleware.
 */

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Method not allowed', 405);
}

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

// Cookie con valor vacío + expires en el pasado = browser la elimina.
// Es CRÍTICO que los mismos atributos (path, domain, secure, samesite) que
// se usaron al setear estén acá — sino el browser ve dos cookies distintas
// y la vieja sobrevive.
setcookie('_jwt_panel', '', $cookieOpts);

apiOk(['ok' => true]);
