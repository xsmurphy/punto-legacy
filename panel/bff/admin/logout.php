<?php
/**
 * BFF — Logout del admin realm. Borra la cookie _jwt_admin.
 */

require_once __DIR__ . '/../lib/api_client.php';

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie('_jwt_admin', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => $isHttps,
]);

bffJson(['ok' => true]);
