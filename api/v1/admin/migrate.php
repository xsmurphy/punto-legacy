<?php
/**
 * Endpoint one-shot para correr las migraciones SQL pendientes manualmente.
 *
 *   POST /v1/admin/migrate?token=<MIGRATE_TOKEN>
 *
 * Existe como fallback si el auto-migrate del docker-entrypoint.sh no se
 * dispara (ej. Coolify cacheó la layer del COPY del entrypoint y la versión
 * vieja del script quedó en el container). El user con acceso al panel puede
 * gatillar la migración sin shell a la BD.
 *
 * Gating: env `MIGRATE_TOKEN` (string random; configurar una vez en Coolify
 * env vars). El query param `token` debe matchear. Si la env no está seteada,
 * el endpoint devuelve 503. Esto evita que el endpoint sea hit por un atacante
 * que descubra la URL — sin el token correcto, 403.
 *
 * NO usa apiAuthTenant porque la migración debe poder correrse incluso si la
 * sesión del user no autentica (ej. cookie domain mal, schema-required-by-bootstrap
 * a medio aplicar). El token compensa la falta de auth de sesión.
 *
 * Idempotente: el script subyacente trackea en `schema_migrations` qué archivos
 * ya pasaron — re-hits no causan daño, solo retornan "nothing to apply".
 */

require_once __DIR__ . '/../../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido — usar POST', 405);
}

$expected = $_ENV['MIGRATE_TOKEN'] ?? '';
if ($expected === '') {
    apiError('MIGRATE_TOKEN no configurado en env del backend', 503);
}

$provided = (string) ($_GET['token'] ?? '');
if (!hash_equals($expected, $provided)) {
    apiError('Token inválido', 403);
}

$repoRoot = dirname(__DIR__, 3);
$script   = $repoRoot . '/database/migrate.php';
if (!is_file($script)) {
    apiError("Script no encontrado en $script", 500);
}

// Lo corremos como subprocess para que sus exit() no terminen este request
// y para tener el código de salida limpio. Redirige stderr a stdout para
// capturar los errores de pg_query en el log que devolvemos.
$phpBin = PHP_BINARY ?: 'php';
$cmd    = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' 2>&1';

if (!function_exists('exec')) {
    apiError('exec() deshabilitado en este PHP', 500);
}

$output   = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);
$log = implode("\n", $output);

if ($exitCode !== 0) {
    apiError($log ?: "Migración falló sin output (exit $exitCode)", 500);
}

apiOk([
    'log'      => $log,
    'exitCode' => $exitCode,
    'status'   => 'ok',
]);
