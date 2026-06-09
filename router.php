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

// Resolver archivo del módulo. Caso especial admin.*: si el prepend /admin
// transformó /scripts/foo.js en /admin/scripts/foo.js pero el archivo NO existe
// en panel/admin/scripts/, hacer fallback a panel/scripts/ — los assets comunes
// (jquery, bootstrap, common.js) viven en panel/, no en panel/admin/.
$resolvedPath = $path;
$moduleDir    = __DIR__ . '/' . $module;
$resolvedFile = $moduleDir . $resolvedPath;
if (!is_file($resolvedFile) && str_starts_with($host, 'admin.') && str_starts_with($path, '/admin/')) {
    $fallbackPath = substr($path, 6); // strip "/admin"
    $fallback = $moduleDir . $fallbackPath;
    if (is_file($fallback)) {
        $resolvedFile = $fallback;
        $resolvedPath = $fallbackPath;
    }
}

$ext = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
$isPhpExecutable = in_array($ext, ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8'], true);

// CRÍTICO: paths a .php SIEMPRE se ejecutan vía require, NUNCA via readfile.
// Si los servimos como estático leakeamos el source PHP entero (info disclosure P0).
// El módulo's router.php hace `return false` para estos, asumiendo que PHP-S los
// sirve desde docroot — pero nuestro docroot es /var/www (no /var/www/panel),
// así que PHP-S no los encuentra. Por eso los ejecutamos acá explícitamente.
if ($isPhpExecutable && $path !== '/' && is_file($resolvedFile)) {
    chdir($moduleDir);
    require $resolvedFile;
    return true;
}

// Servir estáticos del módulo (CSS, JS, imágenes, fonts) por readfile, porque
// PHP-S sirve estáticos desde su docroot fijo y nuestro docroot "real" depende
// del Host.
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

// Si el path se resolvió a admin/ fallback (admin.* → panel/), persistirlo en
// $_SERVER para que el módulo router vea el path correcto.
if ($resolvedPath !== $path) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $_SERVER['REQUEST_URI'] = $resolvedPath . ($qs !== '' ? '?' . $qs : '');
}

// Delegar al router del módulo para paths no resueltos (extension-less que mapean
// a .php, default index, 404, etc.). chdir() para que __DIR__ relativos sigan ok.
chdir($moduleDir);
require $moduleDir . '/router.php';
return true;
