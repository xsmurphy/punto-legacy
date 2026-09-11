<?php
declare(strict_types=1);

/**
 * Worker CLI del migrador ENCOM → Punto (context/77, D3).
 *
 *     php api/scripts/migration_worker.php <jobId> <companyId>
 *
 * Lo dispara el drain `POST /v1/maintenance?job=migration-drain`, que YA tomó
 * el job de la cola (`status='running'`) y le pasa acá el par que le tocó.
 *
 * ── Por qué un proceso aparte y no inline en el drain ────────────────────
 * Dos razones independientes:
 *
 *   1. **El contexto de tenant se fija UNA vez por proceso.** `COMPANY_ID` y
 *      `TODAY` son `define()` (`api/data.php`), no variables. El proceso que
 *      atiende el drain no está en el contexto del tenant destino y no puede
 *      entrar sin dejárselo puesto a todo lo que venga después en esa misma
 *      request. Acá el proceso NACE para una empresa y muere con ella.
 *   2. **Duración.** El export son decenas de requests contra el legacy
 *      PACEADAS a 60/min: minutos de pared, muy por encima del timeout de
 *      PHP-FPM. El drain dispara y contesta; este proceso sigue solo.
 *
 * Por eso las constantes se definen ANTES de `bootstrap.php` (mismo patrón que
 * los arneses de `api/tests/` y que `run_sale_chain.php`).
 *
 * Salida: 0 si el job terminó `done`, 1 si terminó `failed`, 2 si ni siquiera
 * se pudo arrancar. El drain no espera el resultado — queda en la fila del job.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$jobId     = trim((string) ($argv[1] ?? ''));
$companyId = trim((string) ($argv[2] ?? ''));

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, $jobId) || !preg_match($uuidRe, $companyId)) {
    fwrite(STDERR, "Uso: php migration_worker.php <jobId> <companyId>\n");
    exit(2);
}

// ── Contexto de tenant, antes del bootstrap ─────────────────────────────
// No se pasa por `api/data.php` a propósito: ese archivo además de las
// constantes carga settings, módulos y catálogos del comercio, y exige un
// outlet/register/user activos que una empresa recién creada —el caso NORMAL
// de un destino de migración— todavía no tiene. Los servicios de import
// reciben `$companyId` por argumento; lo único que necesitan del entorno
// global son estas constantes.
define('COMPANY_ID', $companyId);
define('OUTLET_ID', '');
define('USER_ID', '');
define('REGISTER_ID', '');
define('ROLE_ID', '');
define('TODAY', date('Y-m-d H:i:s'));

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomMigrationService.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomImportService.php';
require_once dirname(__DIR__) . '/lib/Admin/EncomClient.php';

use Punto\Api\Admin\EncomClient;
use Punto\Api\Admin\EncomImportService;
use Punto\Api\Admin\EncomMigrationService;

$svc = new EncomMigrationService();

$job = ncmExecute(
    'SELECT jobid, companyid, domains, credentials, progress
       FROM migration_job
      WHERE jobid = ? AND companyid = ? LIMIT 1',
    [$jobId, $companyId]
);

if (!$job) {
    fwrite(STDERR, "[migration_worker] job $jobId no encontrado\n");
    exit(2);
}

$jsonOf = static function (mixed $raw, mixed $default): mixed {
    if (is_array($raw)) {
        return $raw;
    }
    if (is_string($raw) && $raw !== '') {
        return json_decode($raw, true) ?? $default;
    }
    return $default;
};

$domains     = (array) $jsonOf($job['domains'] ?? null, []);
$credentials = (array) $jsonOf($job['credentials'] ?? null, []);
$progress    = (array) $jsonOf($job['progress'] ?? null, []);
$options     = (array) ($progress['options'] ?? []);

$errors = [];
$log    = [];
$status = 'failed';

try {
    if (($credentials['cookies'] ?? null) === null) {
        throw new \RuntimeException(
            'El job no tiene la sesión del legacy: ya se consumió o caducó. Creá la migración de nuevo.'
        );
    }

    $baseUrl = (string) ($credentials['legacyUrl'] ?? (defined('ENCOM_MIGRATION_URL') ? ENCOM_MIGRATION_URL : ''));
    $client  = EncomClient::fromCookies($baseUrl, (array) $credentials['cookies']);

    $result = (new EncomImportService($companyId, $client, $jobId))->run($domains, $options);

    $progress = $result['progress'] + ['options' => $options];
    $errors   = $result['errors'];
    $log      = $result['log'];

    // Un dominio que falló NO se puede reportar como `done`: el operador
    // vería verde y no volvería a mirar. Con errores el job queda `failed`
    // aunque parte se haya importado — el detalle dice exactamente qué entró.
    $status = $errors === [] ? 'done' : 'failed';

    // ── D6: qué importó, en la auditoría del tenant DESTINO ──────────────
    // Va a `tenant_audit` (la del comercio) y no solo a `admin_audit`: el
    // dueño tiene que poder ver en SU bitácora de dónde salieron los datos
    // que aparecieron en su cuenta.
    tenantAudit(
        ['companyId' => $companyId, 'userId' => null, 'outletId' => null, 'realm' => 'admin'],
        'POST',
        '/v1/admin/migrations',
        $jobId,
        ['source' => 'encom', 'domains' => array_values($domains), 'progress' => $result['progress'], 'status' => $status]
    );
} catch (\Throwable $e) {
    $errors[] = ['domain' => 'job', 'message' => $e->getMessage(), 'at' => gmdate('c')];
    $status   = 'failed';
    error_log('[migration_worker] job=' . $jobId . ' error=' . $e->getMessage());
}

// `finish()` borra las credenciales pase lo que pase: una sesión viva del
// panel de un cliente no se queda guardada esperando al próximo intento.
$svc->finish($jobId, $status, $progress, $errors, $log);

fwrite(STDOUT, '[migration_worker] job=' . $jobId . ' status=' . $status .
    ' progress=' . json_encode($progress, JSON_UNESCAPED_UNICODE) . "\n");

exit($status === 'done' ? 0 : 1);
