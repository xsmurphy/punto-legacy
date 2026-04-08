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

// Servir archivos desde ../assets/ (vendor libs, images compartidas)
if (strpos($path, '/assets/') === 0) {
    $assetFile = dirname(__DIR__) . $path;
    if (file_exists($assetFile)) {
        $ext = pathinfo($assetFile, PATHINFO_EXTENSION);
        $mimeTypes = [
            'js'   => 'application/javascript',
            'css'  => 'text/css',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'svg'  => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2'=> 'font/woff2',
            'ttf'  => 'font/ttf',
        ];
        header('Content-Type: ' . ($mimeTypes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($assetFile);
        return true;
    }
}

// Regla: vendor.* -> filesCompiler.php
if (preg_match('/^\/vendor\.(.*)$/', $path, $matches)) {
    $_GET['vendor'] = $matches[1];
    require __DIR__ . '/filesCompiler.php';
    return true;
}

// Regla: URLs sin extension -> .php (local) o proxy a panel API
if ($path !== '/' && !pathinfo($path, PATHINFO_EXTENSION)) {
    $phpFile = __DIR__ . $path . '.php';
    if (file_exists($phpFile)) {
        require $phpFile;
        return true;
    }

    // Proxy to panel (dev only — in production nginx routes these)
    if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false) {
        $panelUrl = 'http://localhost:8002' . $uri;
        $ctx = stream_context_create([
            'http' => [
                'header'         => 'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '') . "\r\n",
                'timeout'        => 10,
                'ignore_errors'  => true,  // don't fail on 4xx/5xx
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $response = @file_get_contents($panelUrl, false, $ctx);
        if ($response !== false) {
            foreach ($http_response_header ?? [] as $h) {
                // Forward status code and content-type
                if (stripos($h, 'HTTP/') === 0 || stripos($h, 'Content-Type:') === 0) {
                    header($h);
                }
            }
            echo $response;
            return true;
        }
    }
}

// Default: index.html (static SPA shell)
if ($path === '/' || $path === '/index' || $path === '/index.html') {
    header('Cache-Control: no-store');
    readfile(__DIR__ . '/index.html');
    return true;
}

// Archivo no encontrado
http_response_code(404);
echo "404 Not Found: $path";
