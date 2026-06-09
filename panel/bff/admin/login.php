<?php
/**
 * BFF — Login del admin realm. POST { email, password }.
 *
 * Llama a la API (que verifica y mintea el token) y, si OK, setea la cookie HttpOnly `_jwt_admin`
 * (same-origin con el front /admin). El token NUNCA llega al browser como JSON — solo como cookie
 * HttpOnly. Separada de `_jwt_panel` (realm tenant).
 */

require_once __DIR__ . '/../lib/api_client.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    bffJson(['ok' => false, 'error' => 'método no permitido'], 405);
}

$res = bffApiPost('v1/admin/login.php', [
    'email'    => $_POST['email'] ?? '',
    'password' => $_POST['password'] ?? '',
]);

if (!$res['ok'] || empty($res['data']['token'])) {
    bffFailFromApi($res);
}

$ttl     = (int) (getenv('ADMIN_JWT_TTL') ?: ($_ENV['ADMIN_JWT_TTL'] ?? 28800));
// HTTPS detection detrás de Traefik/proxy via X-Forwarded-Proto.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
setcookie('_jwt_admin', $res['data']['token'], [
    'expires'  => time() + $ttl,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $isHttps,
]);

// No devolvemos el token al front (queda solo en la cookie HttpOnly).
bffJson(['ok' => true, 'data' => ['email' => $res['data']['email'] ?? '', 'name' => $res['data']['name'] ?? '']]);
