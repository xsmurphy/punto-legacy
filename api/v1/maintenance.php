<?php
/**
 * REST — Endpoint interno de jobs de mantenimiento periódicos.
 *
 *   POST /v1/maintenance?job=<rollup-reconcile|purge-tenant-audit|purge-deleted-row|einvoice-drain>
 *       → { processed?, deleted?, issued?, errors?, skipped?, job }
 *
 * SIN apiAuthTenant: lo invoca el cron DENTRO de la imagen del API
 * (`api/docker/cron/maintenance.sh` vía `crond`), nunca un operador de panel
 * ni un device. Mismo criterio de auth que el drainer de FE
 * (`api/v1/einvoice.php?action=drain`): secreto compartido en header
 * `X-Maintenance-Secret`, comparado con `hash_equals`, 503 si la env var no
 * está configurada (nunca "abierto por accidente").
 *
 * Reusa la constante `EINVOICE_DRAIN_SECRET` (definida en simple.config.php) —
 * NO se agrega una env var nueva. El nombre quedó del drainer de FE, pero
 * conceptualmente es el "secreto de jobs internos del sistema": cubre el
 * drain de FE y estos jobs de mantenimiento. Renombrar la env var en
 * Coolify es innecesario (ya está cargada en prod) y agregaría un paso de
 * deploy manual que no hace falta.
 *
 * Jobs (ver context/06-infraestructura.md § Jobs de mantenimiento):
 *   - rollup-reconcile   → SELECT rollup_reconcile(?p_max) (mig 41). `limit`
 *                          en query, default 500, tope 5000.
 *   - purge-tenant-audit → DELETE FROM tenant_audit WHERE createdat < now() - 2 months
 *                          (mig 36; la columna es camelCase en el CREATE TABLE
 *                          pero mig 150 la normalizó a `createdat` lowercase —
 *                          NO citar `"createdAt"`, no matchea).
 *   - purge-deleted-row  → DELETE FROM deleted_row WHERE deleted_at < now() - 90 days
 *                          (mig 138; `deleted_at` siempre fue lowercase, no quoted).
 *   - einvoice-drain     → delega en EInvoiceService::drain() (mismo código que
 *                          `einvoice.php?action=drain`, sin duplicar lógica) —
 *                          único punto de entrada para el cron de la imagen.
 *
 * Lock: cada job corre bajo pg_try_advisory_lock(hashtext('maintenance:'||job)).
 * Si no consigue el lock (otra corrida del mismo job ya está adentro — dos
 * ticks del cron pisándose, o el día de mañana dos réplicas del API pegándole
 * al mismo Postgres) responde 200 `{skipped:true, reason:'already running'}`,
 * nunca error. El lock se libera SIEMPRE, incluso si el job tira excepción —
 * ver nota más abajo sobre por qué el release NO puede vivir en un `finally`
 * que contenga un `apiOk`/`apiError` (esas funciones hacen `exit`, que en PHP
 * NO dispara `finally`).
 */

require_once __DIR__ . '/../bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$job    = (string) ($_GET['job'] ?? '');

if ($method !== 'POST') {
    apiError('Método no permitido', 405);
}

// ── Auth: secreto compartido, ANTES de cualquier otra cosa (mismo criterio
// que el drainer). Sin secreto configurado → 503 siempre. ──
if (!defined('EINVOICE_DRAIN_SECRET') || EINVOICE_DRAIN_SECRET === '') {
    apiError('EINVOICE_DRAIN_SECRET no configurado — los jobs de mantenimiento están deshabilitados.', 503);
}
$given = (string) ($_SERVER['HTTP_X_MAINTENANCE_SECRET'] ?? ($_GET['secret'] ?? ''));
if ($given === '' || !hash_equals(EINVOICE_DRAIN_SECRET, $given)) {
    apiError('Secreto inválido', 403);
}

$knownJobs = ['rollup-reconcile', 'purge-tenant-audit', 'purge-deleted-row', 'einvoice-drain'];
if (!in_array($job, $knownJobs, true)) {
    apiError('job desconocido: ' . $job, 422);
}

/**
 * Boolean de Postgres vía PDO pgsql: llega como 't'/'f' textual, NUNCA como
 * bool nativo de PHP (el driver no auto-castea) — mismo caveat que el resto
 * del repo (ver ItemsQuery::_boolFromPg / DashboardService). Comparar con
 * === 'f' sería un bug: 'f' es un string no-vacío, truthy en PHP.
 */
function maintenancePgBoolTrue(mixed $v): bool
{
    return $v === true || $v === 't' || $v === 'true' || $v === '1' || $v === 1;
}

function maintenanceRunJob(string $job): array
{
    global $db;

    switch ($job) {
        case 'rollup-reconcile':
            $limitRaw = (int) ($_GET['limit'] ?? 500);
            $limit    = $limitRaw > 0 && $limitRaw <= 5000 ? $limitRaw : 500;
            $processed = (int) $db->GetOne('SELECT rollup_reconcile(?)', [$limit]);
            return ['processed' => $processed, 'limit' => $limit];

        case 'purge-tenant-audit':
            $deleted = ncmExecute("DELETE FROM tenant_audit WHERE createdat < now() - interval '2 months'");
            return ['deleted' => (int) ($deleted === false ? 0 : $deleted)];

        case 'purge-deleted-row':
            $deleted = ncmExecute("DELETE FROM deleted_row WHERE deleted_at < now() - interval '90 days'");
            return ['deleted' => (int) ($deleted === false ? 0 : $deleted)];

        case 'einvoice-drain':
            $limitRaw = (int) ($_GET['limit'] ?? 20);
            $limit    = $limitRaw > 0 && $limitRaw <= 200 ? $limitRaw : 20;
            return (new \Punto\Api\EInvoice\EInvoiceService())->drain($limit);

        default:
            // Inalcanzable: $job ya validado contra $knownJobs arriba.
            throw new \RuntimeException('job desconocido: ' . $job);
    }
}

global $db;

$lockKey = 'maintenance:' . $job;
$locked  = $db->GetOne('SELECT pg_try_advisory_lock(hashtext(?))', [$lockKey]);
if (!maintenancePgBoolTrue($locked)) {
    apiOk(['skipped' => true, 'reason' => 'already running', 'job' => $job]);
}

// IMPORTANTE: acá adentro NO se llama apiOk()/apiError() — esas funciones
// hacen `exit`, y `exit` dentro de un `try` NO dispara el `finally` en PHP
// (a diferencia de `return`/`throw`, que sí lo hacen). El release del lock
// tiene que quedar garantizado con try/catch/finally puro, y recién DESPUÉS
// —ya fuera del try— se arma la respuesta HTTP y se sale.
$result   = null;
$errorMsg = null;
try {
    $result = maintenanceRunJob($job);
} catch (\Throwable $e) {
    $errorMsg = $e->getMessage();
} finally {
    // Liberar siempre, haya salido bien o mal el job. pg_advisory_unlock
    // devuelve false si el lock no era nuestro (no debería pasar acá), pero
    // no hay nada más que hacer con eso — best-effort.
    $db->GetOne('SELECT pg_advisory_unlock(hashtext(?))', [$lockKey]);
}

if ($errorMsg !== null) {
    error_log('[maintenance] job=' . $job . ' result=error message=' . $errorMsg);
    apiError($errorMsg, 500);
}

error_log('[maintenance] job=' . $job . ' result=' . json_encode($result, JSON_UNESCAPED_UNICODE));
apiOk(array_merge($result, ['job' => $job]));
