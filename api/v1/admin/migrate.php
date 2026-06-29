<?php
/**
 * Endpoint one-shot para correr las migraciones SQL pendientes manualmente.
 *
 *   POST /v1/admin/migrate
 *
 * Existe como fallback si el auto-migrate del docker-entrypoint.sh no se
 * dispara (ej. Coolify cacheó la layer del COPY del entrypoint y la versión
 * vieja del script quedó en el container). El operador hit'ea esta URL desde
 * browser/curl y la migración se aplica.
 *
 * SIN AUTH: durante DEV (frontend-dev.punto.la) la URL no es pública y el
 * worst-case de un attacker que la descubra es triggerear las migraciones
 * que YA están en el repo — no puede inyectar SQL custom. El script solo
 * aplica archivos `database/migrations/postgres/NN_*.sql` que existen en
 * disco (controlados por git, no por input del request).
 *
 * Cuando el frontend pase a panel.punto.la (URL pública), agregar auth
 * acá o eliminar el archivo. Por ahora la simplicidad ganan al teatro de
 * seguridad.
 *
 * Idempotente: el script subyacente trackea en `schema_migrations`; re-hits
 * no causan daño, solo devuelven "todo al día".
 */

require_once __DIR__ . '/../../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido — usar POST', 405);
}

$repoRoot = dirname(__DIR__, 3);
$script   = $repoRoot . '/database/migrate.php';
if (!is_file($script)) {
    apiError("Script no encontrado en $script", 500);
}

if (!function_exists('exec')) {
    apiError('exec() deshabilitado en este PHP', 500);
}

// Subprocess: el migrate.php hace exit(), no queremos que termine este request.
// stderr → stdout para capturar los errors de pg_query en el log.
$phpBin = PHP_BINARY ?: 'php';
$cmd    = escapeshellarg($phpBin) . ' ' . escapeshellarg($script) . ' 2>&1';

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
