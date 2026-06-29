<?php
/**
 * Router del container PHP — api auto-contenido.
 *
 * Topología (2026-06-29): build context = ./api, WORKDIR = /var/www.
 * Este router es la entrada única del built-in server (php -S 0.0.0.0:3000 router.php).
 * Ya no existe un router raíz en el repo; toda la lógica de despacho vive aquí.
 *
 * Orden de resolución:
 *   1. Paths .php directamente en el árbol → ejecutar vía require (anti-traversal: solo /v1/).
 *   2. Paths sin extensión bajo /v1/ → agregar .php y resolver.
 *   3. Archivos estáticos existentes → servir con Content-Type correcto.
 *   4. Todo lo demás → 404 JSON.
 *
 * Uso local: PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:3000 router.php
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// SUPERFICIE PÚBLICA: sólo los endpoints versionados (/v1/...). bootstrap.php, lib/,
// services/ y router.php NO son web-accessibles. Confina dentro de /v1 (anti-traversal).
$confined = static function (string $candidate): ?string {
    $real = realpath($candidate);
    $base = __DIR__ . DIRECTORY_SEPARATOR . 'v1' . DIRECTORY_SEPARATOR;
    return ($real && str_starts_with($real, $base) && is_file($real) && pathinfo($real, PATHINFO_EXTENSION) === 'php') ? $real : null;
};

if (preg_match('#^/v1/#', $path)) {
    // Con o sin extensión: /v1/tables → /v1/tables.php
    $candidate = pathinfo($path, PATHINFO_EXTENSION) ? __DIR__ . $path : __DIR__ . $path . '.php';
    $phpFile   = $confined($candidate);
    if ($phpFile !== null) {
        require $phpFile;
        return true;
    }
}

// Servir archivos estáticos que existan en el árbol (CSS, JS, imágenes, fonts, etc.).
// Los archivos .php fuera de /v1/ NO se ejecutan ni se sirven.
$resolvedFile = __DIR__ . $path;
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$isPhpLike = in_array($ext, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8'], true);

if (!$isPhpLike && $path !== '/' && is_file($resolvedFile)) {
    $mime = match ($ext) {
        'css'           => 'text/css',
        'js', 'mjs'     => 'application/javascript',
        'json'          => 'application/json',
        'html', 'htm'   => 'text/html; charset=utf-8',
        'svg'           => 'image/svg+xml',
        'png'           => 'image/png',
        'jpg', 'jpeg'   => 'image/jpeg',
        'gif'           => 'image/gif',
        'webp'          => 'image/webp',
        'ico'           => 'image/x-icon',
        'woff'          => 'font/woff',
        'woff2'         => 'font/woff2',
        'ttf'           => 'font/ttf',
        'otf'           => 'font/otf',
        'eot'           => 'application/vnd.ms-fontobject',
        'pdf'           => 'application/pdf',
        'txt', 'log'    => 'text/plain; charset=utf-8',
        'xml'           => 'application/xml',
        'map'           => 'application/json',
        'mp4'           => 'video/mp4',
        'webm'          => 'video/webm',
        'mp3'           => 'audio/mpeg',
        'wav'           => 'audio/wav',
        default         => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($resolvedFile));
    readfile($resolvedFile);
    return true;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => ['message' => 'Not Found', 'code' => 404]]);
