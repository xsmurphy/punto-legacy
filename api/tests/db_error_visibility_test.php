<?php
declare(strict_types=1);

/**
 * Test de integración (Postgres real) del CONTRATO DE ERRORES del wrapper
 * PDO (`api/includes/lib/DB.php`).
 *
 * Hasta 2026-08-22 `Execute()/AutoExecute()/GetOne()/GetRow()/SelectLimit()`
 * capturaban el `PDOException`, logueaban y devolvían `false`. De 1.602
 * call-sites de `Execute(` solo 4 chequeaban ese false, así que un error SQL
 * se degradaba a "recordset vacío" y el número equivocado llegaba al usuario
 * con HTTP 200. Dos bugs shipped vivieron meses así (context/10-roadmap.md):
 * el `max(uuid)` del reporte de producción y el 23502 de
 * `RoleService::_savePermissions()`.
 *
 * Este test fija el contrato nuevo. Tiene que correr contra Postgres REAL:
 * `php -l` y un arnés con doubles NO detectan nada de esto (es exactamente la
 * trampa anotada en `context/_handoff.md`).
 *
 * Casos:
 *   (a) SELECT a una columna inexistente lanza DbQueryException con SQLSTATE
 *       42703, y la excepción NO filtra los valores de los params.
 *   (b) INSERT que viola NOT NULL lanza DbQueryException 23502 y deja
 *       `HasFailedTrans()===true` (antes: transOk seguía true y CompleteTrans
 *       mandaba COMMIT sobre una transacción ya abortada → "guardado OK" sin
 *       nada persistido).
 *   (c) dentro de StartTrans, tras la excepción NO queda transacción abierta:
 *       0 backends en 'idle in transaction' (el rollback del wrapper corre
 *       antes de propagar, porque el caller nunca llega a su CompleteTrans).
 *   (d) kill-switch DB_THROW_ON_ERROR=false → vuelve `false`, NO lanza
 *       (corre en subproceso: la constante se define una sola vez por
 *       proceso).
 *   (e) el guard de cierre de período sigue lanzando PeriodClosedException,
 *       NO DbQueryException — se chequea primero y su contrato (HTTP 409 +
 *       mensaje amigable) no cambió.
 *   (f) repro del bug histórico: `max(uuid)` (agregación imposible en PG,
 *       SQLSTATE 42883) ahora explota visible en vez de devolver vacío.
 *   (g) ErrorMsg()/FirstError() siguen poblados después de la excepción — los
 *       callers que reportan la causa real (SaleAbortedException::$dbError)
 *       dependen de eso.
 *   (h) GetOne()/GetRow()/SelectLimit()/Insert() propagan (no se comen la
 *       excepción por delegar en Execute), y `false` de GetRow/GetOne sigue
 *       significando "sin filas", no "error".
 *
 * Uso (necesita Postgres migrado; `run_db_error_visibility_test.sh` levanta
 * todo de cero en Docker):
 *   POSTGRES_HOST=... POSTGRES_PORT=... POSTGRES_DB=... POSTGRES_USER=... POSTGRES_PASSWORD=... \
 *   php -d variables_order=EGPCS api/tests/db_error_visibility_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Support\DbQueryException;
use Punto\Api\Support\PeriodClosedException;

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

// El wrapper hace error_log() de cada fallo ANTES de lanzar: es correcto y
// deseable en prod, pero acá ensucia la salida del arnés con ~15 líneas de
// stderr que parecen errores reales. Las mandamos a un archivo temporal.
$errLogBackup = ini_get('error_log');
$errLogTmp    = sys_get_temp_dir() . '/db_error_visibility_test_' . getmypid() . '.log';
ini_set('error_log', $errLogTmp);

echo "=== contrato de errores del wrapper DB (api/includes/lib/DB.php) ===\n\n";

// ─────────────────────────────────────────────────────────────────────────
// (a) SELECT a columna inexistente → DbQueryException, SQLSTATE 42703
// ─────────────────────────────────────────────────────────────────────────
$caught = null;
try {
    $db->Execute('SELECT columna_que_no_existe FROM company WHERE companyid = ?', ['00000000-0000-0000-0000-000000000000']);
} catch (DbQueryException $e) {
    $caught = $e;
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    '(a) SELECT a columna inexistente lanza DbQueryException',
    $caught instanceof DbQueryException,
    'se obtuvo: ' . ($caught === null ? 'NINGUNA excepción (el wrapper se tragó el error)' : get_class($caught) . ' — ' . $caught->getMessage()),
    $failures
);
check(
    '(a) SQLSTATE correcto (42703 undefined_column)',
    $caught instanceof DbQueryException && $caught->sqlState() === '42703',
    'sqlState=' . ($caught instanceof DbQueryException ? $caught->sqlState() : 'n/a'),
    $failures
);
check(
    '(a) la excepción lleva el SQL y el CONTEO de params, NUNCA los valores (PII)',
    $caught instanceof DbQueryException
        && str_contains($caught->sql(), 'columna_que_no_existe')
        && $caught->paramCount() === 1
        && !str_contains($caught->sql() . '|' . $caught->getMessage(), '00000000-0000-0000-0000-000000000000'),
    'sql=' . ($caught instanceof DbQueryException ? $caught->sql() : 'n/a')
        . ' paramCount=' . ($caught instanceof DbQueryException ? $caught->paramCount() : 'n/a'),
    $failures
);

// ─────────────────────────────────────────────────────────────────────────
// (b) INSERT que viola NOT NULL → lanza y deja HasFailedTrans()===true
//
// `contact.companyId` es NOT NULL SIN default (verificado contra el schema
// real: en `company` todas las NOT NULL tienen default, así que no sirve).
// Se inserta dentro de una transacción para poder observar transOk.
// ─────────────────────────────────────────────────────────────────────────
$caught = null;
$db->StartTrans();
try {
    $db->AutoExecute('contact', ['companyId' => null, 'contactName' => 'notnull-test'], 'INSERT');
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    '(b) INSERT con NOT NULL violado lanza DbQueryException',
    $caught instanceof DbQueryException,
    'se obtuvo: ' . ($caught === null ? 'NINGUNA excepción' : get_class($caught) . ' — ' . $caught->getMessage()),
    $failures
);
check(
    '(b) SQLSTATE 23502 (not_null_violation)',
    $caught instanceof DbQueryException && $caught->sqlState() === '23502',
    'sqlState=' . ($caught instanceof DbQueryException ? $caught->sqlState() : 'n/a'),
    $failures
);
check(
    '(b) transOk quedó en false — HasFailedTrans() lo refleja',
    $db->HasFailedTrans() === true,
    'HasFailedTrans()=' . var_export($db->HasFailedTrans(), true),
    $failures
);

// ─────────────────────────────────────────────────────────────────────────
// (c) tras la excepción no queda transacción abierta
//
// El caller (acá, este test) nunca llegó a su CompleteTrans(): sin el
// rollback que el wrapper hace antes de propagar, la conexión quedaría en
// 'idle in transaction' hasta que muera el proceso PHP. Se mide con una
// SEGUNDA conexión PDO — la del wrapper no puede ver su propio estado de
// forma confiable después de un abort.
// ─────────────────────────────────────────────────────────────────────────
$pgHost = $_ENV['POSTGRES_HOST'] ?? getenv('POSTGRES_HOST') ?: 'localhost';
$pgPort = (int) ($_ENV['POSTGRES_PORT'] ?? getenv('POSTGRES_PORT') ?: 5432);
$pgDb   = $_ENV['POSTGRES_DB']   ?? getenv('POSTGRES_DB')   ?: 'puntoDB';
$pgUser = $_ENV['POSTGRES_USER'] ?? getenv('POSTGRES_USER') ?: 'punto';
$pgPass = $_ENV['POSTGRES_PASSWORD'] ?? getenv('POSTGRES_PASSWORD') ?: '';

$watcher = new PDO(
    "pgsql:host={$pgHost};port={$pgPort};dbname={$pgDb}",
    $pgUser,
    $pgPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$idle = (int) $watcher->query(
    "SELECT count(*) FROM pg_stat_activity WHERE state = 'idle in transaction' AND pid <> pg_backend_pid()"
)->fetchColumn();
check(
    '(c) 0 backends en "idle in transaction" — el wrapper hizo rollback antes de propagar',
    $idle === 0,
    "pg_stat_activity idle-in-transaction = $idle",
    $failures
);
check(
    '(c) la conexión sigue usable después del abort (StartTrans nuevo funciona)',
    (function () use ($db): bool {
        $db->StartTrans();
        $one = $db->GetOne('SELECT 1');
        $db->CompleteTrans();
        return (int) $one === 1;
    })(),
    'la conexión quedó envenenada tras el rollback del wrapper',
    $failures
);

// ─────────────────────────────────────────────────────────────────────────
// (d) kill-switch DB_THROW_ON_ERROR=false → vuelve `false`, no lanza
//
// La constante se define una sola vez por proceso (simple.config.php), así
// que este caso corre en un subproceso con la env var puesta a 'false'.
// Mismo patrón de subproceso que usa sale_void_test.php para los casos que
// hacen exit().
// ─────────────────────────────────────────────────────────────────────────
$phpBin = PHP_BINARY;
$cmd    = 'DB_THROW_ON_ERROR=false ' . escapeshellarg($phpBin) . ' -d variables_order=EGPCS '
    . escapeshellarg(__DIR__ . '/_db_throw_off_once_cli.php') . ' 2>&1';
$output = trim((string) shell_exec($cmd));
check(
    '(d) DB_THROW_ON_ERROR=false → Execute() devuelve false y NO lanza',
    str_contains($output, 'RESULT=false_no_throw'),
    "salida del subproceso: $output",
    $failures
);
check(
    '(d) con el switch apagado, ErrorMsg() sigue teniendo la causa (comportamiento histórico intacto)',
    str_contains($output, 'ERRMSG_OK'),
    "salida del subproceso: $output",
    $failures
);

// ─────────────────────────────────────────────────────────────────────────
// (e) period_closed sigue lanzando PeriodClosedException, no DbQueryException
//
// Se levanta el SQLSTATE PC001 con el literal 'period_closed' desde un
// bloque PL/pgSQL — el mismo que produce `fn_period_guard()` (mig 157), sin
// depender de que haya un período cerrado cargado en esta base.
// ─────────────────────────────────────────────────────────────────────────
$caught = null;
try {
    $db->Execute("DO $$ BEGIN RAISE EXCEPTION 'period_closed' USING ERRCODE = 'PC001'; END $$;");
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    '(e) el guard de período sigue lanzando PeriodClosedException (NO DbQueryException)',
    $caught instanceof PeriodClosedException && !($caught instanceof DbQueryException),
    'se obtuvo: ' . ($caught === null ? 'NINGUNA excepción' : get_class($caught)),
    $failures
);
check(
    '(e) el mensaje al usuario sigue siendo el amigable, no el DETAIL crudo del trigger',
    $caught instanceof PeriodClosedException && !str_contains($caught->getMessage(), 'period_closed'),
    'mensaje=' . ($caught ? $caught->getMessage() : 'n/a'),
    $failures
);

// ─────────────────────────────────────────────────────────────────────────
// (f) repro del bug histórico: max(uuid) en el reporte de producción
//
// PG NO tiene max(uuid) (SQLSTATE 42883, undefined_function). Antes esto
// devolvía false → el caller lo leía como "sin filas" → el reporte de
// producción salía vacío con HTTP 200 y el bug vivió meses.
// ─────────────────────────────────────────────────────────────────────────
$caught = null;
$legacyBehaviour = null;
try {
    $rs = $db->Execute('SELECT max(companyid) AS ultimo FROM company');
    // Comportamiento viejo: $rs === false, y el caller típico lo trataba como
    // recordset vacío → reporte en blanco, sin error.
    $legacyBehaviour = ($rs === false) ? 'false silencioso' : 'devolvió filas';
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    '(f) max(uuid) del reporte de producción ahora explota visible (antes: vacío silencioso)',
    $caught instanceof DbQueryException,
    'se obtuvo: ' . ($caught === null ? "NINGUNA excepción — $legacyBehaviour" : get_class($caught)),
    $failures
);
check(
    '(f) SQLSTATE 42883 (undefined_function: no existe max(uuid) en PG)',
    $caught instanceof DbQueryException && $caught->sqlState() === '42883',
    'sqlState=' . ($caught instanceof DbQueryException ? $caught->sqlState() : 'n/a'),
    $failures
);

// ─────────────────────────────────────────────────────────────────────────
// (g) ErrorMsg()/FirstError() siguen poblados tras la excepción
//
// SaleService y SignupService reportan la causa real leyendo ErrorMsg().
// El catch del wrapper sigue poblando lastError/firstError ANTES de lanzar,
// así que ese flujo no se rompe.
// ─────────────────────────────────────────────────────────────────────────
$db->StartTrans(); // resetea firstError
try {
    $db->Execute('SELECT otra_columna_inexistente FROM outlet');
} catch (DbQueryException $e) {
    // esperado
}
check(
    '(g) ErrorMsg() devuelve la causa real de PG después de lanzar',
    str_contains($db->ErrorMsg(), 'otra_columna_inexistente'),
    'ErrorMsg()=' . $db->ErrorMsg(),
    $failures
);
check(
    '(g) FirstError() guarda la PRIMERA causa de la transacción, no la cascada 25P02',
    str_contains($db->FirstError(), 'otra_columna_inexistente'),
    'FirstError()=' . $db->FirstError(),
    $failures
);

// ─────────────────────────────────────────────────────────────────────────
// (h) los métodos que delegan en Execute propagan; `false` sigue siendo
//     "sin filas" y no "error"
// ─────────────────────────────────────────────────────────────────────────
$propagates = [];
foreach ([
    'GetOne'      => fn() => $db->GetOne('SELECT no_existe FROM company'),
    'GetRow'      => fn() => $db->GetRow('SELECT no_existe FROM company'),
    'SelectLimit' => fn() => $db->SelectLimit('SELECT no_existe FROM company', 1),
    'GetAssoc'    => fn() => $db->GetAssoc('SELECT no_existe FROM company'),
    'Insert'      => fn() => $db->Insert('contact', ['companyId' => null, 'contactName' => 'notnull-test']),
] as $name => $fn) {
    try {
        $fn();
        $propagates[$name] = 'no lanzó';
    } catch (DbQueryException $e) {
        $propagates[$name] = 'ok';
    } catch (\Throwable $e) {
        $propagates[$name] = get_class($e);
    }
}
$bad = array_filter($propagates, fn($v) => $v !== 'ok');
check(
    '(h) GetOne/GetRow/SelectLimit/GetAssoc/Insert propagan DbQueryException',
    $bad === [],
    'no propagaron: ' . json_encode($bad),
    $failures
);
check(
    '(h) `false` de GetRow/GetOne sigue significando "sin filas", no "error"',
    $db->GetRow('SELECT companyid FROM company WHERE companyid = ?', ['00000000-0000-0000-0000-000000000000']) === false
        && $db->GetOne('SELECT companyid FROM company WHERE companyid = ?', ['00000000-0000-0000-0000-000000000000']) === false,
    'una query sin filas dejó de devolver false',
    $failures
);

ini_set('error_log', $errLogBackup === false ? '' : (string) $errLogBackup);
@unlink($errLogTmp);

if ($failures > 0) {
    echo "\n$failures caso(s) fallido(s).\n";
    exit(1);
}
echo "\nTodos los casos OK.\n";
exit(0);
