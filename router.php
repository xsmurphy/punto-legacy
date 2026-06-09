<?php
/**
 * Router raíz — dispatcher por Host header (deploy single-container).
 *
 * Punto sirve 4 subdominios bajo el mismo container PHP en Coolify:
 *   panel.punto.la  → /panel
 *   admin.punto.la  → /panel (con /admin prefix forzado en path)
 *   app.punto.la    → /app
 *   api.punto.la    → /api
 *
 * Cada módulo conserva su router.php — este dispatcher solo decide CUÁL
 * delegar y maneja archivos estáticos (porque PHP -S sirve estáticos vía
 * docroot fijo, y nosotros tenemos 4 docroots virtuales).
 *
 * Uso:
 *   php -S 0.0.0.0:80 router.php
 *
 * En dev local sigue funcionando levantar cada módulo en su propio puerto
 * (panel:8001, app:8002, api:8000). El root router.php es para prod.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Resolver módulo por subdomain
$module = match (true) {
    str_starts_with($host, 'panel.') => 'panel',
    str_starts_with($host, 'admin.') => 'panel', // mismo container, prefix forzado abajo
    str_starts_with($host, 'app.')   => 'app',
    str_starts_with($host, 'api.')   => 'api',
    default                          => 'panel',
};

// admin.* → forzar /admin prefix (replica el chain stripPrefix+addPrefix de Traefik)
// Si el browser pide /login → reescribir a /admin/login.
// Si ya viene /admin/users → dejarlo igual.
if (str_starts_with($host, 'admin.') && !str_starts_with($path, '/admin')) {
    $newPath = '/admin' . $path;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $_SERVER['REQUEST_URI'] = $newPath . ($qs !== '' ? '?' . $qs : '');
    $path = $newPath;
}

// Servir estáticos del módulo (CSS, JS, imágenes, fonts).
// PHP -S sirve estáticos desde su docroot fijo (-t flag), pero nuestro docroot
// "real" depende del Host. Por eso los servimos a mano vía readfile.
$staticFile = __DIR__ . '/' . $module . $path;
if ($path !== '/' && is_file($staticFile)) {
    $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
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
    header('Content-Length: ' . filesize($staticFile));
    readfile($staticFile);
    return true;
}

// Delegar al router del módulo. chdir() para que __DIR__ relativos sigan ok,
// y require para que el módulo gobierne completo desde su propia lógica.
$moduleDir = __DIR__ . '/' . $module;
chdir($moduleDir);
require $moduleDir . '/router.php';
return true;
