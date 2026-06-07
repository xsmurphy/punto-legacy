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

// DEV: en localhost desactivamos TODO el cache HTTP del browser para que
// cada hard-reload traiga la versión fresca del disco. En prod el cache lo
// resuelve el reverse proxy / CDN, no este router.
$isLocalhost = strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false
            || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
if ($isLocalhost) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Servir archivos estaticos que existen (css, js, images, fonts)
// EXCLUIR .php — deben ejecutarse, no servirse como texto plano.
$_pathExt = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($path !== '/' && $_pathExt !== 'php' && file_exists(__DIR__ . $path)) {
    // En localhost servimos manualmente para preservar los headers de
    // no-cache emitidos arriba — el `return false` cede al PHP built-in
    // server y descarta nuestros headers HTTP.
    if ($isLocalhost) {
        $ext = $_pathExt; // ya calculado arriba (y sin .php)
        $mime = [
            'js'    => 'application/javascript',
            'css'   => 'text/css',
            'html'  => 'text/html',
            'json'  => 'application/json',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'ico'   => 'image/x-icon',
            'webp'  => 'image/webp',
            'woff'  => 'font/woff', 'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf', 'otf'   => 'font/otf',
            'eot'   => 'application/vnd.ms-fontobject',
            'mp3'   => 'audio/mpeg', 'wav' => 'audio/wav',
            'mp4'   => 'video/mp4',  'webm' => 'video/webm',
            'pdf'   => 'application/pdf',
            'txt'   => 'text/plain', 'xml' => 'application/xml',
            'map'   => 'application/json',
        ][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile(__DIR__ . $path);
        return true;
    }
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
        // Solo cache largo en prod; en localhost ya seteamos no-store arriba
        // y NO queremos pisarlo. `header()` sin chequeo sobrescribiría.
        if (!$isLocalhost) {
            header('Cache-Control: public, max-age=31536000, immutable');
        }
        readfile($assetFile);
        return true;
    }
}

// Regla: archivos .php explícitos — SOLO directorios de entrypoints conocidos.
// Includes, bootstrap y partials NO deben ejecutarse por URL directa.
// realpath() + comprobación inside __DIR__ para prevenir path traversal.
if ($_pathExt === 'php' && preg_match('#^/(API|bff)/#i', $path)) {
    $phpFile = realpath(__DIR__ . $path);
    if ($phpFile !== false && str_starts_with($phpFile, __DIR__ . DIRECTORY_SEPARATOR)) {
        require $phpFile;
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
        $panelUrl = 'http://localhost:8001' . $uri; // panel dev port (NO self → evita loop infinito)
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
