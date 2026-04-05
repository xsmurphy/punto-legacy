<?php
/**
 * Router para PHP built-in server (app)
 * Uso: php -S localhost:8000 router.php
 *
 * Replica las reglas de .htaccess:
 * 1. URLs sin extension -> .php
 * 2. vendor.* -> filesCompiler.php
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

// Security headers — aplican a todas las respuestas del módulo /app
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Servir archivos estaticos que existen (css, js, images, fonts)
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false; // PHP built-in server sirve el archivo directamente
}

// Regla: vendor.* -> filesCompiler.php
if (preg_match('/^\/vendor\.(.*)$/', $path, $matches)) {
    $_GET['vendor'] = $matches[1];
    require __DIR__ . '/filesCompiler.php';
    return true;
}

// Regla: URLs sin extension -> .php
if ($path !== '/' && !pathinfo($path, PATHINFO_EXTENSION)) {
    $phpFile = __DIR__ . $path . '.php';
    if (file_exists($phpFile)) {
        require $phpFile;
        return true;
    }
}

// Default: index.php
if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

// Archivo no encontrado
http_response_code(404);
echo "404 Not Found: $path";
