<?php
/**
 * Genera un JWT pos-app fresco (15s) y redirige a app/handoff.php.
 *
 * Por qué existe:
 *   El token SSO tenía TTL 15s para minimizar ventana de replay en logs de Traefik,
 *   pero se emitía al renderizar el sidebar del panel. Si el usuario esperaba más de
 *   15s antes de hacer click en "Caja", el token ya estaba expirado y handoff.php
 *   devolvía "token inválido". Este endpoint emite el token en el momento del click.
 *
 * Auth: valida el cookie _jwt_panel (realm 'panel').
 * Identidad desde la cookie firmada; registerId via query liviana a la BD.
 */

require_once __DIR__ . '/../../includes/simple.config.php';
require_once __DIR__ . '/../../includes/jwt.php';
require_once __DIR__ . '/../../includes/db.php';

$panelToken = $_COOKIE['_jwt_panel'] ?? '';
if ($panelToken === '') {
    header('Location: /login');
    exit;
}

$jwtSecret = $_ENV['JWT_SECRET'] ?? '';
if ($jwtSecret === '') {
    http_response_code(500);
    die('pos-redirect: JWT no configurado');
}

$panel = jwtDecode($panelToken, $jwtSecret);
if (!is_array($panel) || ($panel['iss'] ?? '') !== 'panel') {
    header('Location: /login');
    exit;
}

$_sub  = (string)($panel['sub'] ?? '');
$_cid  = (string)($panel['cid'] ?? '');
$_oid  = (string)($panel['oid'] ?? '');
$_role = (int)($panel['role'] ?? 1);

if ($_sub === '' || $_cid === '' || $_oid === '') {
    header('Location: /login');
    exit;
}

$_posBase = defined('APP_URL') && APP_URL ? APP_URL : (defined('POS_URL') ? POS_URL : '');
if ($_posBase === '') {
    http_response_code(500);
    die('pos-redirect: APP_URL no configurada');
}

// Primer registro del outlet (mirror del query original en functions.php).
$_registerId = (string)($db->GetOne(
    'SELECT registerId FROM register WHERE outletId = ? ORDER BY registerId ASC LIMIT 1',
    [$_oid]
) ?? '');

$_now  = time();
$token = jwtEncode([
    'iss'  => 'pos-app',
    'sub'  => $_sub,
    'cid'  => $_cid,
    'oid'  => $_oid,
    'rid'  => $_registerId,
    'role' => $_role,
    'iat'  => $_now,
    'exp'  => $_now + 15,
], $jwtSecret);

header('Location: ' . $_posBase . '/handoff.php?t=' . rawurlencode($token));
exit;
