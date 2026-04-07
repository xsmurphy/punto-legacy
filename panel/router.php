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

// Servir archivos estaticos que existen (css, js, images, fonts)
// Incluye /assets/ que es un symlink a ../assets/
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false; // PHP built-in server sirve el archivo directamente
}

// Fallback para URLs de resize del CDN: /assets/{w}-{h}/... → imagenotfound.jpg
if (preg_match('|^/assets/\d+-\d+/|', $path)) {
    $fallback = __DIR__ . '/../assets/images/imagenotfound.jpg';
    header('Content-Type: image/jpeg');
    readfile($fallback);
    return true;
}

// Regla: URLs sin extension -> .php (incluye API/)
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
