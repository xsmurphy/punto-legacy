<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) de las 4 REGRESIONES que provocó el
 * cambio de contrato de `api/includes/lib/DB.php` (2026-08-22: el wrapper
 * PDO pasó de devolver `false` ante un error de SQL a LANZAR
 * `Punto\Api\Support\DbQueryException`). Los 4 fixes ya están en el árbol de
 * trabajo — este test es el que los hubiera atrapado si hubiera existido
 * antes. `php -l`/mocks NO detectan nada de esto: hace falta Postgres real
 * (misma trampa que ya documenta `db_error_visibility_test.php`).
 *
 * Casos:
 *
 *   (a) `CommitTrans()`/`RollbackTrans()` YA NO enmascaran el error real.
 *       Desde que el wrapper lanza, `failTransaction()` hace `rollBack()`
 *       ANTES de propagar — así que cuando el caller llega a su catch (los
 *       9 call-sites reales del repo que hacen `BeginTrans()` +
 *       `try {...} catch (\Throwable) { $db->RollbackTrans(); }`, ej.
 *       `PaymentsService::creditInvoice()`, `CompanyAdminService`,
 *       `VariantService`) el PDO YA NO tiene transacción abierta. SIN la
 *       guarda `inTransaction()` que agregó el fix, ese
 *       `CommitTrans()`/`RollbackTrans()` sin protección tira
 *       `PDOException("There is no active transaction")` DESDE el catch y
 *       REEMPLAZA el mensaje real de PG que el operador necesitaba ver.
 *       Se emula el patrón exacto de esos 9 call-sites (no se llama a
 *       ningún service — se ejercita el wrapper directo, que es donde vive
 *       el fix) para los dos lados: un call-site que revierte y uno que
 *       confirma dentro del mismo patrón try/catch.
 *
 *   (b) Idempotencia del webhook de dLocal —
 *       `PaymentsService::creditInvoice()`. Bug real: el `INSERT` en
 *       `ai_credit_ledger` dejó de devolver `false` en la violación del
 *       índice único parcial `uq_ai_credit_ledger_invoice_grant` (mig 30)
 *       cuando el wrapper pasó a lanzar — el `if ($ins === false)` que
 *       reconocía el duplicado nunca se ejecutaba, así que un reintento de
 *       webhook (dLocal reintenta agresivamente) tiraba `DbQueryException`
 *       sin atrapar → 500 → dLocal reintenta el pago para siempre. El fix
 *       agrega un `catch (DbQueryException)` explícito que chequea
 *       SQLSTATE 23505. Se inyecta un doble de `PaymentProvider` (nueva
 *       interfaz, `PaymentsService::__construct(?PaymentProvider $provider)`)
 *       para ejercitar `handleWebhook()` de punta a punta sin llamar a la
 *       API real de dLocal.
 *
 *   (c) Un error de BD en `RegisterLeaseService::holderConflict()` (lectura
 *       de `register_lease`, la exclusividad de caja de context/29) NO tumba
 *       el LOTE ENTERO de `api/v1/offline-sync.php`. Antes del fix, un error
 *       de SQL ahí tiraba `DbQueryException` sin atrapar y abortaba TODAS
 *       las ventas encoladas del device (violando offline-first,
 *       context/08 §53: son ventas YA EMITIDAS E IMPRESAS). El fix agrega un
 *       `try/catch (DbQueryException)` alrededor de esa llamada puntual, que
 *       falla SOLO ese ítem con `SERVER_ERROR` y sigue con el resto del
 *       lote. Se rompe `register_lease` de verdad (RENAME COLUMN temporal —
 *       Postgres descartable, DDL reversible, se restaura en `finally`) y se
 *       dirve el endpoint REAL con un POST HTTP de verdad contra el servidor
 *       embebido de PHP (`php -S`, docroot `api/v1/`) + `curl`. NO es el
 *       subproceso "seteo $_SERVER + require directo" que usan
 *       `_pos_auth_once_cli.php`/`_sale_void_once_cli.php`: ese patrón
 *       depende de que `file_get_contents('php://input')` lea STDIN, y
 *       verificado empíricamente contra este PHP 8.4.24, el SAPI `cli` puro
 *       NO implementa esa lectura (sí lo hace `cli-server`, que es
 *       justamente lo que expone `php -S`) — con `require` directo,
 *       `offline-sync.php` siempre hubiera visto `sales[]` vacío. Ver el
 *       comentario en el caso (c) más abajo.
 *
 *   (d) El texto crudo de PG nunca llega al body HTTP de una venta abortada.
 *       `SaleAbortedException::clientMessage()` es la ÚNICA superficie que
 *       puede viajar al cliente — `$dbError` es solo para
 *       `error_log`/GlitchTip. Se verifica el CONTRATO de la excepción
 *       directamente (`clientMessage()` no contiene nada de `$dbError`) y,
 *       por grep, que ninguno de los DOS call-sites reales que arman una
 *       respuesta HTTP de venta abortada (`api/v1/sales.php`,
 *       `api/v1/offline-sync.php`) pasa `$e->dbError` a
 *       `apiError()`/`apiConflict()`/`apiUnprocessable()`.
 *
 *       NO se armó un fixture de venta completo para forzar un abort real
 *       vía `sales.php` (necesitaría ítems/impuestos/pagos válidos de punta
 *       a punta solo para llegar al mismo `catch (SaleAbortedException)` que
 *       ya se puede auditar de forma más precisa por contrato + grep de los
 *       ÚNICOS dos lugares donde ese texto podría escaparse). Se documenta
 *       acá explícitamente porque el brief de este test lo pedía como
 *       decisión a declarar.
 *
 * Uso (necesita Postgres migrado + seed.sql de verify_chain cargado — ver
 * `run_db_error_contract_regressions_test.sh` para levantar todo de cero en
 * Docker):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/db_error_contract_regressions_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Billing\PaymentsService;
use Punto\Api\Billing\Payments\PaymentProvider;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Support\DbQueryException;

/** @var DB $db */
global $db;

$failures = 0;

function check(string $label, bool $ok, string $detail, int &$failures): void
{
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

/** Backends en 'idle in transaction' vistos desde una conexión APARTE. */
function idleInTransactionCount(string $host, int $port, string $dbName, string $user, string $pass): int
{
    $watcher = new PDO(
        "pgsql:host={$host};port={$port};dbname={$dbName}",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return (int) $watcher->query(
        "SELECT count(*) FROM pg_stat_activity WHERE state = 'idle in transaction' AND pid <> pg_backend_pid()"
    )->fetchColumn();
}

// El wrapper hace error_log() de cada fallo ANTES de lanzar — correcto en
// prod, pero ensucia la salida del arnés. Se manda a un archivo temporal
// (mismo criterio que db_error_visibility_test.php).
$errLogBackup = ini_get('error_log');
$errLogTmp    = sys_get_temp_dir() . '/db_error_contract_regressions_test_' . getmypid() . '.log';
ini_set('error_log', $errLogTmp);

echo "=== regresiones del contrato de errores del wrapper DB (2026-08-22) ===\n\n";

$pgHost = $_ENV['POSTGRES_HOST'] ?? getenv('POSTGRES_HOST') ?: 'localhost';
$pgPort = (int) ($_ENV['POSTGRES_PORT'] ?? getenv('POSTGRES_PORT') ?: 5432);
$pgDb   = $_ENV['POSTGRES_DB']   ?? getenv('POSTGRES_DB')   ?: 'puntoDB';
$pgUser = $_ENV['POSTGRES_USER'] ?? getenv('POSTGRES_USER') ?: 'punto';
$pgPass = $_ENV['POSTGRES_PASSWORD'] ?? getenv('POSTGRES_PASSWORD') ?: '';

// ═════════════════════════════════════════════════════════════════════════
// (a) RollbackTrans()/CommitTrans() ya no enmascaran "There is no active
//     transaction" sobre el error real de PG.
// ═════════════════════════════════════════════════════════════════════════

// ── (a.1) patrón de los 9 call-sites que revierten en el catch ──
$db->BeginTrans();
$caughtA1 = null;
try {
    $db->Execute('SELECT columna_que_no_existe FROM company');
} catch (DbQueryException $e) {
    $caughtA1 = $e;
} catch (\Throwable $e) {
    $caughtA1 = $e;
}
check(
    '(a.1) BeginTrans + statement roto lanza DbQueryException',
    $caughtA1 instanceof DbQueryException,
    'se obtuvo: ' . ($caughtA1 === null ? 'NINGUNA excepción' : get_class($caughtA1) . ' — ' . $caughtA1->getMessage()),
    $failures
);

$rollbackThrew  = null;
$rollbackResult = null;
try {
    $rollbackResult = $db->RollbackTrans();
} catch (\Throwable $e) {
    $rollbackThrew = $e;
}
check(
    '(a.1) RollbackTrans() desde el catch NO lanza ("no active transaction" ya no escapa)',
    $rollbackThrew === null,
    'lanzó: ' . ($rollbackThrew !== null ? get_class($rollbackThrew) . ' — ' . $rollbackThrew->getMessage() : 'n/a'),
    $failures
);
check('(a.1) RollbackTrans() devuelve true', $rollbackResult === true, 'devolvió: ' . var_export($rollbackResult, true), $failures);
// NOTA: se compara contra $caughtA1->getMessage() (lo que el caller REAL
// lee — los 9 call-sites de BeginTrans/RollbackTrans leen la causa del
// `catch (DbQueryException $e)`, ej. `$e->getMessage()`/`$e->sqlState()` en
// PaymentsService::creditInvoice()), NO contra `$db->ErrorMsg()`/
// `FirstError()`: esos dos son el mecanismo de `StartTrans()/CompleteTrans()`
// (el `firstError` solo se resetea en `StartTrans()`, nunca en
// `BeginTrans()`), un patrón distinto y no relacionado que este bloque no
// ejercita.
check(
    '(a.1) el mensaje que el caller reporta (la excepción atrapada) conserva el texto ORIGINAL de PG, sin "no active transaction"',
    $caughtA1 instanceof DbQueryException
        && str_contains($caughtA1->getMessage(), 'columna_que_no_existe')
        && !str_contains($caughtA1->getMessage(), 'no active transaction'),
    'excepción=' . ($caughtA1 instanceof DbQueryException ? $caughtA1->getMessage() : 'n/a'),
    $failures
);
$idleA1 = idleInTransactionCount($pgHost, $pgPort, $pgDb, $pgUser, $pgPass);
check('(a.1) sin backend "idle in transaction" colgado tras el RollbackTrans() del catch', $idleA1 === 0, "idle-in-transaction=$idleA1", $failures);

// ── (a.2) mismo riesgo del lado del commit — call-site que confirma dentro
//          del mismo patrón try/catch tras un statement que ya abortó ──
$db->BeginTrans();
$caughtA2 = null;
try {
    $db->Execute('SELECT otra_columna_que_no_existe FROM outlet');
} catch (DbQueryException $e) {
    $caughtA2 = $e;
} catch (\Throwable $e) {
    $caughtA2 = $e;
}
check(
    '(a.2) BeginTrans + statement roto lanza DbQueryException',
    $caughtA2 instanceof DbQueryException,
    'se obtuvo: ' . ($caughtA2 === null ? 'NINGUNA excepción' : get_class($caughtA2) . ' — ' . $caughtA2->getMessage()),
    $failures
);

$commitThrew  = null;
$commitResult = null;
try {
    $commitResult = $db->CommitTrans();
} catch (\Throwable $e) {
    $commitThrew = $e;
}
check(
    '(a.2) CommitTrans() desde el catch NO lanza ("no active transaction" ya no escapa)',
    $commitThrew === null,
    'lanzó: ' . ($commitThrew !== null ? get_class($commitThrew) . ' — ' . $commitThrew->getMessage() : 'n/a'),
    $failures
);
check('(a.2) CommitTrans() devuelve true', $commitResult === true, 'devolvió: ' . var_export($commitResult, true), $failures);
check(
    '(a.2) el mensaje que el caller reporta (la excepción atrapada) conserva el texto ORIGINAL de PG, sin "no active transaction"',
    $caughtA2 instanceof DbQueryException
        && str_contains($caughtA2->getMessage(), 'otra_columna_que_no_existe')
        && !str_contains($caughtA2->getMessage(), 'no active transaction'),
    'excepción=' . ($caughtA2 instanceof DbQueryException ? $caughtA2->getMessage() : 'n/a'),
    $failures
);
$idleA2 = idleInTransactionCount($pgHost, $pgPort, $pgDb, $pgUser, $pgPass);
check('(a.2) sin backend "idle in transaction" colgado tras el CommitTrans() del catch', $idleA2 === 0, "idle-in-transaction=$idleA2", $failures);

// Sanity: la conexión sigue usable después de los dos aborts.
$db->StartTrans();
$sanity = $db->GetOne('SELECT 1');
$db->CompleteTrans();
check('(a) la conexión sigue usable después de ambos aborts', (int) $sanity === 1, 'GetOne("SELECT 1")=' . var_export($sanity, true), $failures);

// ═════════════════════════════════════════════════════════════════════════
// (b) dLocal webhook — idempotencia de PaymentsService::creditInvoice()
// ═════════════════════════════════════════════════════════════════════════

/** Doble de PaymentProvider — devuelve SIEMPRE el mismo pago 'paid' fijo. */
class DbErrorRegressionsFakeProvider implements PaymentProvider
{
    public function __construct(private string $fixedPaymentId)
    {
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createPayment(array $args): array
    {
        return ['providerPaymentId' => $this->fixedPaymentId, 'redirectUrl' => 'https://example.test/checkout'];
    }

    public function getPayment(string $providerPaymentId): array
    {
        return ['status' => 'paid', 'raw' => ['id' => $providerPaymentId, 'status' => 'PAID']];
    }

    public function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        return true;
    }

    public function extractPaymentId(array $body): ?string
    {
        return $this->fixedPaymentId;
    }
}

$bCompanyId = (string) $db->GetOne('SELECT gen_random_uuid()');
$db->Insert('company', [
    'companyId' => $bCompanyId,
    'status'    => 'active',
    'plan'      => 0,
    'balance'   => 0,
    'isParent'  => false,
    'config'    => '{}',
]);

$bPackId = (string) $db->GetOne('SELECT gen_random_uuid()');
$db->Insert('credit_pack', [
    'id'       => $bPackId,
    'slug'     => 'test_db_error_regressions_' . substr(str_replace('-', '', $bPackId), 0, 12),
    'name'     => 'Pack de test (db_error_contract_regressions_test)',
    'credits'  => 500,
    'priceUsd' => 3.00,
    'active'   => true,
]);

$bPaymentId = 'dlocal_test_' . bin2hex(random_bytes(6));
$bInvoiceId = (string) $db->GetOne('SELECT gen_random_uuid()');
$db->Insert('billing_invoice', [
    'id'                => $bInvoiceId,
    'companyId'         => $bCompanyId,
    'type'              => 'pack',
    'amountUsd'         => 3.00,
    'currency'          => 'USD',
    'status'            => 'pending',
    'packId'            => $bPackId,
    'provider'          => 'dlocal_go',
    'providerInvoiceId' => $bPaymentId,
]);

try {
    $svc     = new PaymentsService(new DbErrorRegressionsFakeProvider($bPaymentId));
    $rawBody = (string) json_encode(['payment_id' => $bPaymentId, 'order_id' => $bInvoiceId, 'status' => 'PAID']);
    $headers = ['HTTP_X_SIGNATURE' => 'fake-signature-test'];

    // ── 1. primer webhook: acredita ──
    $r1 = $svc->handleWebhook($rawBody, $headers);
    check('(b) primer webhook: status=paid', ($r1['status'] ?? '') === 'paid', json_encode($r1), $failures);
    check('(b) primer webhook: credited === 500 (créditos del pack)', (int) ($r1['credited'] ?? 0) === 500, json_encode($r1), $failures);

    $ledgerCount1 = (int) $db->GetOne('SELECT COUNT(*) FROM ai_credit_ledger WHERE relatedinvoiceid = ?', [$bInvoiceId]);
    check('(b) exactamente 1 fila en ai_credit_ledger tras el primer webhook', $ledgerCount1 === 1, "count=$ledgerCount1", $failures);

    $balanceAfter1 = (int) $db->GetOne('SELECT aicreditsbalance FROM company WHERE companyid = ?', [$bCompanyId]);
    check('(b) balance de la company subió exactamente 500', $balanceAfter1 === 500, "balance=$balanceAfter1", $failures);

    // ── 2. reproducir la ventana de carrera: reset directo a pending. El
    //      guard primario (SELECT ... FOR UPDATE + status='pending') pasaría
    //      de nuevo si NO existiera el backstop del índice único parcial —
    //      esto simula que dos webhooks concurrentes vieron la invoice
    //      pending a la vez. ──
    $db->Execute("UPDATE billing_invoice SET status = 'pending' WHERE id = ?", [$bInvoiceId]);

    // ── 3. segundo webhook (el "duplicado" concurrente) ──
    $caughtB = null;
    $r2      = null;
    try {
        $r2 = $svc->handleWebhook($rawBody, $headers);
    } catch (\Throwable $e) {
        $caughtB = $e;
    }
    check(
        '(b) segundo webhook NO lanza (ni DbQueryException del 23505 ni ningún 500)',
        $caughtB === null,
        'lanzó: ' . ($caughtB !== null ? get_class($caughtB) . ' — ' . $caughtB->getMessage() : 'n/a'),
        $failures
    );
    check('(b) segundo webhook devuelve status=already_paid', $r2 !== null && ($r2['status'] ?? '') === 'already_paid', json_encode($r2), $failures);

    $ledgerCount2 = (int) $db->GetOne('SELECT COUNT(*) FROM ai_credit_ledger WHERE relatedinvoiceid = ?', [$bInvoiceId]);
    check('(b) sigue habiendo exactamente 1 fila en ai_credit_ledger (no se duplicó)', $ledgerCount2 === 1, "count=$ledgerCount2", $failures);

    $balanceAfter2 = (int) $db->GetOne('SELECT aicreditsbalance FROM company WHERE companyid = ?', [$bCompanyId]);
    check('(b) el balance NO se acreditó dos veces (sigue en 500, no 1000)', $balanceAfter2 === 500, "balance=$balanceAfter2", $failures);
} finally {
    // Limpieza de fixtures propios (best-effort — no deben quedar en la BD).
    try {
        $db->Execute('DELETE FROM ai_credit_ledger WHERE relatedinvoiceid = ?', [$bInvoiceId]);
    } catch (\Throwable) {
    }
    try {
        $db->Execute('DELETE FROM billing_invoice WHERE id = ?', [$bInvoiceId]);
    } catch (\Throwable) {
    }
    try {
        $db->Execute('DELETE FROM credit_pack WHERE id = ?', [$bPackId]);
    } catch (\Throwable) {
    }
    try {
        $db->Execute('DELETE FROM company WHERE companyid = ?', [$bCompanyId]);
    } catch (\Throwable) {
    }
}

// ═════════════════════════════════════════════════════════════════════════
// (c) holderConflict() roto no tumba el lote de offline-sync.php
// ═════════════════════════════════════════════════════════════════════════

// Tenant fixture "Verify PY" (ver api/lib/Sales/verify_chain/seed.sql) —
// mismo fixture que sale_void_test.php / pos_device_revoked_auth_test.php.
$cCompanyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$cOutletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$cRegisterId = '81c541da-640e-4891-a1a0-b32841e64c75';
$cUserId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';

$issuedC = DeviceAuth::issueDeviceToken(
    $cCompanyId,
    $cOutletId,
    $cRegisterId,
    $cUserId,
    'Test device — db_error_contract_regressions (c)',
    'phpunit-like/db_error_contract_regressions_test',
    'test-offline-sync-db-error-' . bin2hex(random_bytes(6)),
);
$cDeviceId = $issuedC['deviceId'];
$cToken    = $issuedC['token'];

$cBody = (string) json_encode([
    'sales' => [
        ['clientTempId' => 'temp-db-error-c-1', 'invoiceNo' => 900001, 'sale' => []],
        ['clientTempId' => 'temp-db-error-c-2', 'invoiceNo' => 900002, 'sale' => []],
    ],
]);
$cBodyFile = tempnam(sys_get_temp_dir(), 'offline_sync_db_error_body_');
file_put_contents($cBodyFile, $cBody);

// DESVÍO DEL BRIEF, documentado a propósito: la idea original era un
// subproceso que setea $_SERVER y hace `require` directo del endpoint,
// leyendo el body con `file_get_contents('php://input')` desde STDIN
// pipeado. Verificado EMPÍRICAMENTE contra el PHP real de este entorno
// (8.4.24, SAPI cli): `php://input` NO lee STDIN bajo el SAPI `cli` puro —
// esa lectura solo existe en SAPIs con manejo de request real (`cli-server`,
// fpm, apache). Un `require` directo (mismo patrón que
// `_pos_auth_once_cli.php`) siempre habría visto `sales[]` vacío,
// exactamente el falso-negativo que se vio en la primera corrida de este
// test contra Postgres real.
//
// Se levanta el servidor embebido de PHP (`php -S`, SAPI `cli-server`, que
// SÍ implementa `php://input` como un request HTTP real) sirviendo
// `api/v1/` como docroot, y se le pega un POST real con `curl` — el
// endpoint corre exactamente igual que en producción (mismo archivo, mismo
// `require_once bootstrap.php` interno), la única diferencia es CÓMO llega
// el request al proceso PHP.
$phpBin      = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$docroot     = dirname(__DIR__) . '/v1';
$serverPort  = 8100 + random_int(0, 4000);
$serverLog   = sys_get_temp_dir() . '/offline_sync_db_error_server_' . getmypid() . '.log';
$serverPidFile = sys_get_temp_dir() . '/offline_sync_db_error_server_' . getmypid() . '.pid';

// `-d variables_order=EGPCS` NO es opcional: sin la `E`, el servidor embebido
// arranca con `$_ENV` VACÍO y `simple.config.php` no ve ninguna POSTGRES_*, así
// que se conecta al default `localhost:5432` — donde no hay nada escuchando.
// Los 4 checks del caso (c) fallaban por "Connection refused" del subproceso,
// no por el comportamiento que este caso quiere medir. El arnés padre ya corre
// con este flag; el hijo lo necesita igual.
$startCmd = 'nohup ' . escapeshellarg($phpBin) . ' -d variables_order=EGPCS -S 127.0.0.1:' . $serverPort . ' -t ' . escapeshellarg($docroot)
    . ' > ' . escapeshellarg($serverLog) . ' 2>&1 & echo $! > ' . escapeshellarg($serverPidFile);
shell_exec($startCmd);

// Esperar a que el server embebido esté aceptando conexiones (hasta 5s).
$serverReady = false;
for ($i = 0; $i < 50; $i++) {
    $conn = @fsockopen('127.0.0.1', $serverPort, $errno, $errstr, 0.2);
    if ($conn !== false) {
        fclose($conn);
        $serverReady = true;
        break;
    }
    usleep(100000);
}
check('(c) setup: el servidor embebido de PHP levantó', $serverReady, "log del server:\n" . (string) @file_get_contents($serverLog), $failures);

$outputC       = '';
$columnRenamed = false;
try {
    // Rompe register_lease de VERDAD: el rename de la columna que
    // holderConflict() lee hace que CUALQUIER SELECT contra ella tire
    // SQLSTATE 42703 (undefined_column), sin importar si hay o no una
    // tenencia activa. DDL reversible, se restaura en el finally — estamos
    // en un Postgres descartable.
    $db->Execute('ALTER TABLE register_lease RENAME COLUMN deviceid TO deviceid_test_break');
    $columnRenamed = true;

    if ($serverReady) {
        $curlCmd = 'curl -s -X POST ' . escapeshellarg('http://127.0.0.1:' . $serverPort . '/offline-sync.php')
            . ' -H ' . escapeshellarg('Authorization: Bearer ' . $cToken)
            . ' -H ' . escapeshellarg('Content-Type: application/json')
            . ' --data-binary ' . escapeshellarg('@' . $cBodyFile)
            . ' 2>&1';
        $outputC = shell_exec($curlCmd) ?? '';
    }
} finally {
    if ($columnRenamed) {
        $db->Execute('ALTER TABLE register_lease RENAME COLUMN deviceid_test_break TO deviceid');
    }
    $serverPid = trim((string) @file_get_contents($serverPidFile));
    if ($serverPid !== '') {
        shell_exec('kill ' . escapeshellarg($serverPid) . ' 2>/dev/null');
    }
    @unlink($cBodyFile);
    @unlink($serverPidFile);
    @unlink($serverLog);
    try {
        ncmExecute('DELETE FROM device WHERE deviceid = ?::uuid', [$cDeviceId]);
    } catch (\Throwable) {
    }
}

$decodedC = json_decode(trim($outputC), true);
check('(c) la respuesta del endpoint es JSON válido (no un 500/stack trace crudo)', is_array($decodedC), "salida del subproceso: $outputC", $failures);
check(
    '(c) el envelope es HTTP-200-shaped: {ok:true, data:{results:[...]}}',
    is_array($decodedC) && ($decodedC['ok'] ?? false) === true && isset($decodedC['data']['results']) && is_array($decodedC['data']['results']),
    "salida del subproceso: $outputC",
    $failures
);

$resultsC = (is_array($decodedC) && is_array($decodedC['data']['results'] ?? null)) ? $decodedC['data']['results'] : [];
check('(c) el lote completo (2 items) fue procesado — no abortó en el primero', count($resultsC) === 2, 'results=' . json_encode($resultsC), $failures);

$allServerError = count($resultsC) === 2;
foreach ($resultsC as $r) {
    $allServerError = $allServerError
        && ($r['ok'] ?? true) === false
        && ($r['error']['code'] ?? '') === 'SERVER_ERROR';
}
check(
    '(c) CADA item viene ok=false con error.code=SERVER_ERROR (holderConflict() rompió — no REGISTER_NOT_HELD, no 500)',
    $allServerError,
    'results=' . json_encode($resultsC),
    $failures
);

// ═════════════════════════════════════════════════════════════════════════
// (d) el texto crudo de PG nunca llega al body HTTP de una venta abortada
// ═════════════════════════════════════════════════════════════════════════

$rawPgText   = 'ERROR:  duplicate key value violates unique constraint "uq_transaction_expedition_invoiceno" '
    . 'DETAIL: Key (companyId, registerId, timbrado, invoiceNo)=(0ea6c5d8-..., 81c541da-..., 12345678, 99) already exists.';
$saleAborted = new SaleAbortedException(dbError: $rawPgText, message: 'Sale transaction aborted (test)');

check(
    '(d) SaleAbortedException::clientMessage() NUNCA contiene el texto crudo de PG (constraint/columnas/valores)',
    !str_contains($saleAborted->clientMessage(), 'uq_transaction_expedition_invoiceno')
        && !str_contains($saleAborted->clientMessage(), 'companyId')
        && !str_contains($saleAborted->clientMessage(), 'DETAIL')
        && $saleAborted->clientMessage() === SaleAbortedException::CLIENT_MESSAGE,
    'clientMessage()=' . $saleAborted->clientMessage(),
    $failures
);
check(
    '(d) $dbError SIGUE disponible para el log/GlitchTip (la causa real no se pierde)',
    $saleAborted->dbError === $rawPgText,
    'dbError=' . ($saleAborted->dbError ?? 'null'),
    $failures
);

// Grep-assert: los DOS call-sites reales que arman una respuesta HTTP de
// venta abortada son api/v1/sales.php y api/v1/offline-sync.php. Ninguno
// debe pasar $e->dbError al helper que arma el body del error — solo
// $e->clientMessage() o un mensaje genérico propio.
//
// NO se armó un fixture de venta completo para forzar un abort real end-to-
// end contra sales.php (necesitaría ítems/impuestos/pagos válidos solo para
// llegar al mismo catch que esto audita de forma más precisa): se declara
// acá explícitamente, como pide el brief de este test.
$scanFiles = [
    dirname(__DIR__) . '/v1/sales.php',
    dirname(__DIR__) . '/v1/offline-sync.php',
];
$leaks = [];
foreach ($scanFiles as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match('/api(?:Error|Conflict|Unprocessable)\s*\([^)]*dbError/', $src)) {
        $leaks[] = $file;
    }
}
check(
    '(d) ningún call-site de sales.php/offline-sync.php pasa $e->dbError a apiError()/apiConflict()/apiUnprocessable()',
    $leaks === [],
    'call-sites sospechosos: ' . json_encode($leaks),
    $failures
);

ini_set('error_log', $errLogBackup === false ? '' : (string) $errLogBackup);
@unlink($errLogTmp);

harnessFinish($failures);
