<?php
/**
 * REST — Endpoint interno de jobs de mantenimiento periódicos.
 *
 *   POST /v1/maintenance?job=<rollup-reconcile|purge-tenant-audit|purge-deleted-row|einvoice-drain|einvoice-reconcile|partition-ensure|period-close|ocr-requeue>
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
 *   - einvoice-reconcile → delega en EInvoiceService::reconcileAll(): consulta a
 *                          SIFEN el estado FISCAL de los documentos ya emitidos
 *                          (`sifen_status`), cross-tenant. SIFEN puede rechazar
 *                          un DE minutos después de devolver un CDC válido —
 *                          sin este job ese rechazo no lo descubre nadie salvo
 *                          que alguien apriete el botón del panel. `limit` en
 *                          query, default 50, tope 200: cada documento es una
 *                          llamada a la API del proveedor.
 *   - partition-ensure   → E1 de context/48-escalamiento-de-datos.md (mig 156):
 *                          `SELECT ensure_month_partitions('transaction'|'itemsold',
 *                          'transactiondate'|'itemsolddate', 12)` + chequeo
 *                          `partition_health(..., 3)`. Si a alguna de las 2
 *                          tablas le faltan particiones para los próximos 3
 *                          meses, alerta a Sentry (mismo patrón best-effort de
 *                          `\Sentry\captureMessage` gateado por `function_exists`
 *                          que `bootstrap.php`) — mismo "falla silenciosa" que
 *                          ya mordió a `rollup_dirty` (134 pendientes sin que
 *                          nadie corriera el job), acá el costo de no alertar
 *                          es un INSERT fallando duro en un mes sin partición.
 *   - period-close       → D7/E1b de context/48-escalamiento-de-datos.md (mig 157):
 *                          `SELECT * FROM period_close_due()` (default 1 mes,
 *                          override por tenant en `company.config->>'settingPeriodCloseMonths'`)
 *                          y por cada `(companyid, period)` pendiente,
 *                          `SELECT period_close_run(companyid, period, NULL, 'job')` —
 *                          inserta el cierre y re-encola el mes completo en
 *                          `rollup_dirty`. Después de un cierre, el trigger
 *                          `fn_period_guard()` empieza a rechazar UPDATE/DELETE
 *                          sobre ese período en `transaction`/`itemsold`/`stock`/
 *                          `cpayments`/`expenses`.
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
// Solo por header: un secreto en la query string termina en logs de acceso y
// del proxy. El único caller (maintenance.sh) usa el header.
$given = (string) ($_SERVER['HTTP_X_MAINTENANCE_SECRET'] ?? '');
if ($given === '' || !hash_equals(EINVOICE_DRAIN_SECRET, $given)) {
    apiError('Secreto inválido', 403);
}

$knownJobs = ['rollup-reconcile', 'purge-tenant-audit', 'purge-deleted-row', 'einvoice-drain', 'einvoice-reconcile', 'partition-ensure', 'period-close', 'ocr-requeue'];
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

        case 'einvoice-reconcile':
            $limitRaw = (int) ($_GET['limit'] ?? 50);
            $limit    = $limitRaw > 0 && $limitRaw <= 200 ? $limitRaw : 50;
            return (new \Punto\Api\EInvoice\EInvoiceService())->reconcileAll($limit);

        case 'ocr-requeue':
            // Rescata borradores de compra que quedaron en 'processing': el
            // proceso que los tomó murió a mitad (típicamente un deploy recicló
            // el contenedor mientras el modelo respondía). Sin esto quedan
            // colgados para siempre — visibles en la bandeja pero sin datos y
            // sin nadie que los vuelva a intentar.
            require_once __DIR__ . '/../lib/Purchases/PurchaseDraftService.php';
            return (new \Punto\Api\Purchases\PurchaseDraftService())->requeueStale();

        case 'partition-ensure':
            return maintenancePartitionEnsure($db);

        case 'period-close':
            return maintenancePeriodClose($db);

        default:
            // Inalcanzable: $job ya validado contra $knownJobs arriba.
            throw new \RuntimeException('job desconocido: ' . $job);
    }
}

/**
 * E1 de context/48-escalamiento-de-datos.md (mig 156). Crea las particiones
 * mensuales que falten (12 meses de margen) para `transaction`/`itemsold` y
 * chequea que haya cobertura para los próximos 3 meses — si no, alerta.
 */
function maintenancePartitionEnsure(\DB $db): array
{
    $tables = [
        'transaction' => 'transactiondate',
        'itemsold'    => 'itemsolddate',
    ];

    $created = [];
    $health  = [];

    foreach ($tables as $table => $column) {
        $createdRaw = $db->GetOne(
            "SELECT array_to_json(ensure_month_partitions(?, ?, 12))",
            [$table, $column]
        );
        $created[$table] = $createdRaw !== false && $createdRaw !== null
            ? (json_decode((string) $createdRaw, true) ?? [])
            : [];

        $health[$table] = (int) $db->GetOne(
            'SELECT partition_health(?, ?, 3)',
            [$table, $column]
        );

        if ($health[$table] < 3) {
            $msg = "[partition-ensure] {$table} tiene cobertura de particiones "
                 . "para menos de 3 meses hacia adelante (health={$health[$table]})";
            error_log($msg);
            if (function_exists('\\Sentry\\captureMessage')) {
                \Sentry\captureMessage($msg, \Sentry\Severity::error());
            }
        }
    }

    return ['created' => $created, 'health' => $health];
}

/**
 * D7/E1b de context/48-escalamiento-de-datos.md (mig 157). Por cada tenant
 * con un período vencido (según `period_close_due()` — default 1 mes, con
 * override por tenant en `company.config->>'settingPeriodCloseMonths'`),
 * corre `period_close_run` para cerrarlo: inserta el cierre en
 * `period_close` y re-encola el mes completo en `rollup_dirty`.
 */
function maintenancePeriodClose(\DB $db): array
{
    $due    = $db->Execute('SELECT * FROM period_close_due()');
    $closed = [];

    if ($due === false) {
        return ['closed' => $closed];
    }

    while (!$due->EOF) {
        $companyId = $due->fields['companyid'];
        $period    = $due->fields['period'];

        $db->GetOne('SELECT period_close_run(?, ?, NULL, ?)', [$companyId, $period, 'job']);
        $closed[] = ['companyId' => $companyId, 'period' => $period];

        $due->MoveNext();
    }

    return ['closed' => $closed];
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
