<?php
/**
 * Router del dev server de la API compartida (/api).
 * Uso: PHP_CLI_SERVER_WORKERS=8 php -S localhost:8000 -t api api/router.php
 *
 * La API es el backend único del sistema: /panel y /app la consumen como clientes
 * (hoy por self-HTTP en dev; mañana en un server dedicado). Ver context/02-arquitectura.md.
 *
 * Regla: URLs sin extensión → .php (igual que .htaccess de los otros módulos).
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// SUPERFICIE PÚBLICA: sólo los endpoints versionados (/v1/...). bootstrap.php, lib/,
// services/ y router.php NO son web-accessibles. Confina dentro de /api/v1 (anti-traversal).
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

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => ['message' => 'Not Found', 'code' => 404]]);
