<?php
/**
 * Router raíz — dispatcher por Host header (deploy single-container).
 *
 * Punto sirve un único backend PHP en Coolify:
 *   api.punto.la → /api
 *
 * panel.punto.la y admin.punto.la fueron eliminados en dissolve-panel (2026-06-29):
 *   - El backend admin se movió a api/v1/admin/ (misma API PHP compartida).
 *   - El front admin vive en frontend/ (Node/Next.js). Configurar en Coolify/Traefik
 *     que panel.punto.la y admin.punto.la apunten al container del frontend (Node),
 *     NO al container PHP — ya no hay PHP que sirva esos dominios.
 *
 * Uso local:
 *   php -S 0.0.0.0:80 router.php   (solo sirve api.*)
 *
 * INFRA FLAG: admin.punto.la + panel.punto.la deben redirigirse al container Next.js
 * en Coolify. El PHP container solo necesita escuchar api.punto.la.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Solo módulo api. panel/* ya no existe.
$moduleDir = __DIR__ . '/api';

$resolvedPath = $path;
$resolvedFile = $moduleDir . $resolvedPath;

$ext = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
$isPhpExecutable = in_array($ext, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8'], true);

// CRÍTICO: paths a .php SIEMPRE se ejecutan vía require, NUNCA via readfile.
if ($isPhpExecutable && $path !== '/' && is_file($resolvedFile)) {
    chdir($moduleDir);
    require $resolvedFile;
    return true;
}

// Servir estáticos del módulo (CSS, JS, imágenes, fonts).
if (!$isPhpExecutable && $path !== '/' && is_file($resolvedFile)) {
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

// Delegar al router de api para paths no resueltos (extension-less → .php, 404, etc.).
chdir($moduleDir);
require $moduleDir . '/router.php';
return true;
