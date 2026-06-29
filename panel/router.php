<?php
/**
 * Router para PHP built-in server (panel)
 * Uso: php -S localhost:8001 router.php
 *
 * Replica las reglas de .htaccess:
 * 1. URLs sin extension -> .php
 * 2. API/* sin extension -> API/*.php
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

// Security headers — aplican a todas las respuestas del módulo /panel
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Servir archivos estaticos que existen (css, js, images, fonts).
// Incluye /assets/ que es un symlink a ../assets/. is_file (no file_exists) para que un path que
// coincide con un DIRECTORIO (ej. /admin ↔ panel/admin/) no corte acá y siga al routing de abajo.
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // PHP built-in server sirve el archivo directamente
}

// Admin realm (/admin) — front estático standalone (NO el shell del tenant). Su auth es propia
// (cookie _jwt_admin); el gate es client-side (home.html pide /API/v1/admin/me.php → 401 redirige a login).
// En prod, replicar con RewriteRule (/admin → admin/home.html, /admin/login → admin/login.html).
$adminStatic = [
    '/admin'           => '/admin/home.html',
    '/admin/login'     => '/admin/login.html',
    '/admin/users'     => '/admin/users.html',
    '/admin/companies' => '/admin/companies.html',
];
if (isset($adminStatic[$path])) {
    $htmlFile = __DIR__ . $adminStatic[$path];
    if (file_exists($htmlFile)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($htmlFile);
        return true;
    }
}

// Regla: URLs sin extension -> .php (incluye API/)
if ($path !== '/' && !pathinfo($path, PATHINFO_EXTENSION)) {
    $phpFile = __DIR__ . $path . '.php';
    if (file_exists($phpFile)) {
        require $phpFile;
        return true;
    }
}

// Archivo no encontrado
http_response_code(404);
echo "404 Not Found: $path";
