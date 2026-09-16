<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés (Postgres REAL) de la idempotencia de ventas por `transactionUID`.
 *
 * El defecto que cierra (2026-09-16): `SaleService::abortSale()` declaraba
 * "venta duplicada" ante CUALQUIER 23505, `offline-sync.php` respondía
 * `ok:true` con el uid puesto donde va el transactionId, y el POS borraba de
 * su cola una venta YA IMPRESA que nunca se había guardado. Además el duplicado
 * no devolvía la venta original, y el pre-check del uid no filtraba por tenant.
 *
 * Casos:
 *   (A) reintento exacto por uid → duplicated con el transactionId REAL, por
 *       el servicio, por `/v1/sales` (aunque el reintento traiga OTRO número)
 *       y por `/v1/offline-sync`.
 *   (B) 23505 de OTRA constraint → NO duplicated: error real en el servicio,
 *       en `/v1/sales` y en `/v1/offline-sync`; el número tomado por otro uid
 *       sigue siendo `DuplicateInvoiceNumberException`.
 *   (C) uid existente en OTRO tenant → no se declara duplicado ni se filtra
 *       su transactionId (servicio, POST, offline-sync y GET).
 *   (D) carrera concurrente del mismo uid (dos procesos, forzada con un LOCK)
 *       → 1 sola venta, 1 solo `einvoice_document`, el perdedor ve duplicated
 *       con el id del ganador.
 *   (E) `GET /v1/sales?uid=` respeta empresa Y sucursal del device.
 *
 * Uso: bash api/tests/run_sale_idempotency_test.sh
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Auth\DeviceAuth;
use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\Exceptions\DuplicateInvoiceNumberException;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Services\RegisterLeaseService;
use Punto\Api\Support\DbQueryException;

// ── Tenant A "Verify PY" (seed.sql) ─────────────────────────────────────────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$userId     = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$roleId     = '1';
require API_APP_DIR . '/data.php';

// ── Tenant B (seed.sql, el otro tenant del verify_chain) ────────────────────
const B_COMPANY  = 'fa8cf679-9003-417e-8726-5b772d3b6e88';
const B_OUTLET   = '6d3cab3a-c040-4428-8090-6790469de3bd';
const B_REGISTER = 'e91e3e74-b593-4833-9ee8-25b8ce9e4454';
const B_USER     = '999986f1-05fe-4d91-841f-156a090e7a15';

// Segunda sucursal del tenant A, propia del arnés (caso E).
const A2_OUTLET   = '1dee0000-0000-4000-8000-0000000000a2';
const A2_REGISTER = '1dee0000-0000-4000-8000-0000000000b2';

const SERVICE_ITEM   = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // sin stock, sin receta
const OTHER_INDEX    = 'harness_idem_uq_other';
const MARCA_DEL_ARNES = 'sale-idempotency-test';

$failures = 0;
$checks   = 0;
$run      = bin2hex(random_bytes(4));

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function salePayload(string $uid, int $invoiceNo, float $price = 11000.0): array
{
    return [
        'transaction' => [
            'uid'      => $uid,
            'type'     => 0,
            'sale'     => [[
                'itemId' => SERVICE_ITEM, 'count' => 1, 'name' => 'idem', 'uniPrice' => $price,
                'price' => $price, 'total' => $price, 'tax' => 0, 'discount' => 0, 'totalDiscount' => 0,
                'user' => '', 'type' => '', 'date' => '', 'note' => '', 'currency' => '', 'uId' => 0,
            ]],
            'subtotal'  => $price,
            'tax'       => 0,
            'discount'  => 0,
            'payment'   => [['type' => 'cash', 'name' => 'Efectivo', 'total' => $price]],
            'date'      => date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'invoiceno' => $invoiceNo,
        ],
    ];
}

function nextInvoiceNo(): int
{
    static $n = null;
    $n ??= random_int(5_000_000, 9_000_000);
    return ++$n;
}

/**
 * Endpoint real en subproceso (apiError hace exit).
 *
 * @return array{status:int, body:string, data:mixed}
 */
function call(string $endpoint, string $method, string $query, array $body, string $bearer): array
{
    $cmd = [
        PHP_BINARY, '-d', 'variables_order=EGPCS',
        '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
        __DIR__ . '/_permission_once_cli.php',
        $endpoint, $method, $query, json_encode($body), '', $bearer, '', '',
    ];
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['status' => 0, 'body' => 'no se pudo abrir el subproceso', 'data' => null];
    }
    fwrite($pipes[0], $body === [] ? '' : json_encode($body));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $status = 0;
    $data   = null;
    if (preg_match('/BODY:(\{.*\})\s*\nHTTP_STATUS:/s', $out, $m)) {
        $env = json_decode($m[1], true);
        if (is_array($env)) {
            $status = ($env['ok'] ?? null) === true ? 200 : (int) ($env['error']['code'] ?? 0);
            $data   = $env['data'] ?? null;
        }
    }
    return ['status' => $status, 'body' => substr($out . $err, 0, 800), 'data' => $data];
}

function postSale(array $payload, string $bearer): array
{
    return call('v1/sales.php', 'POST', '', ['data' => [json_encode($payload)]], $bearer);
}

function offlineSync(array $payload, int $invoiceNo, string $bearer): array
{
    $uid = (string) $payload['transaction']['uid'];
    $res = call('v1/offline-sync.php', 'POST', '', [
        'sales' => [['clientTempId' => $uid, 'invoiceNo' => $invoiceNo, 'sale' => $payload['transaction']]],
    ], $bearer);
    $res['item'] = $res['data']['results'][0] ?? null;
    return $res;
}

function countByUid(string $uid): int
{
    global $db;
    return (int) $db->GetOne('SELECT COUNT(*) FROM transaction_registry WHERE transactionuid = ?', [$uid]);
}

function service(): SaleService
{
    global $db, $companyId, $outletId, $userId, $registerId, $roleId;
    return new SaleService(TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId')), $db);
}

/** Corre save() y clasifica el desenlace. */
function trySave(array $payload): array
{
    global $companyId;
    try {
        $r = service()->save(SaleInput::fromPayload($payload, $companyId));
        return ['kind' => 'created', 'transactionId' => $r->transactionId];
    } catch (DuplicateSaleException $e) {
        return ['kind' => 'duplicate', 'transactionId' => $e->existing->transactionId, 'invoiceNo' => $e->existing->invoiceNo];
    } catch (DuplicateInvoiceNumberException $e) {
        return ['kind' => 'invoice_taken'];
    } catch (SaleAbortedException $e) {
        return ['kind' => 'aborted', 'dbError' => (string) $e->dbError];
    }
}

// ── Fixtures del arnés ──────────────────────────────────────────────────────
$db->Execute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
     ON CONFLICT (outletId) DO NOTHING',
    [A2_OUTLET, 'Idempotencia - Sucursal 2', $companyId]
);
$db->Execute(
    'INSERT INTO register (registerid, registername, registerstatus, registerinvoicenumber, registerticketnumber,
        registerreturnnumber, registerschedulenumber, registerpedidonumber, registerquotenumber, outletid, companyid)
     VALUES (?, ?, TRUE, 1, 1, 1, 1, 1, 1, ?, ?) ON CONFLICT (registerid) DO NOTHING',
    [A2_REGISTER, 'Idempotencia - Caja 2', A2_OUTLET, $companyId]
);

$deviceA = DeviceAuth::issueDeviceToken($companyId, $outletId, $registerId, $userId,
    'Test device — idempotencia A', MARCA_DEL_ARNES, 'test-idem-a-' . $run);
$deviceA2 = DeviceAuth::issueDeviceToken($companyId, A2_OUTLET, A2_REGISTER, $userId,
    'Test device — idempotencia A2', MARCA_DEL_ARNES, 'test-idem-a2-' . $run);
RegisterLeaseService::claim($registerId, $companyId, $outletId, (string) $deviceA['deviceId'], true);
$bearerA  = (string) $deviceA['token'];
$bearerA2 = (string) $deviceA2['token'];

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (A) reintento exacto por uid ===\n";
$uidA = "idem-a-$run";
$noA  = nextInvoiceNo();
$first = trySave(salePayload($uidA, $noA));
check('(A1) primera venta se crea', $first['kind'] === 'created', json_encode($first), $failures, $checks);
$txA = (string) ($first['transactionId'] ?? '');

$again = trySave(salePayload($uidA, $noA));
check('(A2) mismo uid → DuplicateSaleException con el transactionId REAL y el número original',
    $again['kind'] === 'duplicate' && $again['transactionId'] === $txA && $again['invoiceNo'] === $noA,
    json_encode($again), $failures, $checks);

// El POS consume un número NUEVO en cada intento: el duplicado tiene que
// devolver el número que quedó registrado, no el del reintento.
$res = postSale(salePayload($uidA, nextInvoiceNo()), $bearerA);
check('(A3) POST /v1/sales reintento (con otro número) → 200 duplicated, transactionId real, número original',
    $res['status'] === 200
        && ($res['data']['duplicated'] ?? null) === true
        && ($res['data']['transactionId'] ?? '') === $txA
        && (int) ($res['data']['sale']['invoiceNo'] ?? 0) === $noA,
    "status={$res['status']} {$res['body']}", $failures, $checks);

$res = offlineSync(salePayload($uidA, $noA), $noA, $bearerA);
$item = $res['item'];
check('(A4) offline-sync reintento → ok, duplicated, transactionId REAL (no el uid)',
    $res['status'] === 200 && is_array($item) && ($item['ok'] ?? null) === true
        && ($item['duplicated'] ?? null) === true
        && ($item['transactionId'] ?? '') === $txA && ($item['transactionId'] ?? '') !== $uidA,
    "status={$res['status']} " . json_encode($item) . " {$res['body']}", $failures, $checks);
check('(A5) sigue habiendo UNA sola venta con ese uid', countByUid($uidA) === 1, 'n=' . countByUid($uidA), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (B) 23505 de OTRA constraint ≠ duplicado ===\n";
// Índice parcial propio del arnés: la SEGUNDA venta con uid 'idemother-*' choca
// contra él (y contra ningún otro). Es exactamente la forma de un 23505 que no
// es del uid: un satélite, un índice nuevo, cualquier unicidad.
$db->Execute('DROP INDEX IF EXISTS ' . OTHER_INDEX);
$db->Execute('CREATE UNIQUE INDEX ' . OTHER_INDEX . " ON transaction_registry (companyid) WHERE transactionuid LIKE 'idemother-%'");

$o1 = trySave(salePayload("idemother-1-$run", nextInvoiceNo()));
check('(B0) setup: la primera venta del índice se crea', $o1['kind'] === 'created', json_encode($o1), $failures, $checks);

$uidO2 = "idemother-2-$run";
$o2 = trySave(salePayload($uidO2, nextInvoiceNo()));
check('(B1) choque contra otra unicidad → SaleAbortedException, NO duplicado',
    $o2['kind'] === 'aborted', json_encode($o2), $failures, $checks);
check('(B2) el clasificador lee el NOMBRE de la constraint del mensaje real de PG',
    DbQueryException::uniqueViolationConstraint((string) ($o2['dbError'] ?? '')) === OTHER_INDEX,
    'dbError=' . ($o2['dbError'] ?? ''), $failures, $checks);
check('(B3) la venta NO quedó registrada', countByUid($uidO2) === 0, 'n=' . countByUid($uidO2), $failures, $checks);

$uidO3 = "idemother-3-$run";
$noO3  = nextInvoiceNo();
$res   = offlineSync(salePayload($uidO3, $noO3), $noO3, $bearerA);
$item  = $res['item'];
check('(B4) offline-sync → ok:false, sin duplicated (la venta queda en la cola del POS)',
    is_array($item) && ($item['ok'] ?? null) === false && empty($item['duplicated'])
        && ($item['error']['code'] ?? '') === 'SERVER_ERROR',
    "status={$res['status']} " . json_encode($item) . " {$res['body']}", $failures, $checks);

$res = postSale(salePayload("idemother-4-$run", nextInvoiceNo()), $bearerA);
check('(B5) POST /v1/sales → 500, nunca 200 duplicated',
    $res['status'] === 500 && empty($res['data']['duplicated']),
    "status={$res['status']} {$res['body']}", $failures, $checks);
$db->Execute('DROP INDEX IF EXISTS ' . OTHER_INDEX);

$noTaken = nextInvoiceNo();
trySave(salePayload("idem-num-1-$run", $noTaken));
$taken = trySave(salePayload("idem-num-2-$run", $noTaken));
check('(B6) mismo número con OTRO uid → DuplicateInvoiceNumberException (se conserva)',
    $taken['kind'] === 'invoice_taken', json_encode($taken), $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (C) uid existente en OTRO tenant ===\n";
$uidForeign = "idem-foreign-$run";
$db->AutoExecute('transaction', [
    'transactionTotal' => 5000, 'transactionDiscount' => 0, 'transactionUnitsSold' => 1,
    'transactionType' => 0, 'transactionComplete' => true, 'transactionStatus' => 1,
    'transactionDate' => date('Y-m-d H:i:s'),
    'transactionPaymentType' => json_encode([['type' => 'cash', 'price' => 5000, 'total' => 5000]]),
    'invoiceNo' => nextInvoiceNo(), 'timestamp' => time(), 'transactionUID' => $uidForeign,
    'registerId' => B_REGISTER, 'userId' => B_USER, 'outletId' => B_OUTLET, 'companyId' => B_COMPANY,
], 'INSERT');
$txForeign = (string) $db->GetOne('SELECT transactionid FROM transaction_registry WHERE transactionuid = ?', [$uidForeign]);
check('(C0) setup: venta del tenant B con ese uid', $txForeign !== '', 'sin fila', $failures, $checks);

$f1 = trySave(salePayload($uidForeign, nextInvoiceNo()));
check('(C1) servicio del tenant A → error real, NO duplicado con el id de B',
    $f1['kind'] === 'aborted', json_encode($f1), $failures, $checks);

$res = postSale(salePayload($uidForeign, nextInvoiceNo()), $bearerA);
check('(C2) POST /v1/sales → no es 200 y no filtra el transactionId de B',
    $res['status'] !== 200 && !str_contains($res['body'], $txForeign),
    "status={$res['status']} {$res['body']}", $failures, $checks);

$noF = nextInvoiceNo();
$res = offlineSync(salePayload($uidForeign, $noF), $noF, $bearerA);
check('(C3) offline-sync → ok:false y no filtra el transactionId de B',
    is_array($res['item']) && ($res['item']['ok'] ?? null) === false && !str_contains($res['body'], $txForeign),
    "status={$res['status']} {$res['body']}", $failures, $checks);

$res = call('v1/sales.php', 'GET', 'uid=' . rawurlencode($uidForeign), [], $bearerA);
check('(C4) GET ?uid= de otro tenant → 404 (no revela existencia)',
    $res['status'] === 404 && !str_contains($res['body'], $txForeign),
    "status={$res['status']} {$res['body']}", $failures, $checks);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (D) carrera concurrente del mismo uid ===\n";
// Cuenta de FE conectada: la venta encola su documento DENTRO de la
// transacción, y lo que se prueba es que la carrera deja UNO solo.
$db->Execute(
    "INSERT INTO einvoice_account (companyid, provider, username, password_enc, status, environment, config)
     VALUES (?, 'fepy', ?, 'no-usada', 'ok', 'test', '{}'::jsonb)
     ON CONFLICT (companyid) DO UPDATE SET status = 'ok', provider = 'fepy', username = EXCLUDED.username,
        config = '{}'::jsonb",
    [$companyId, MARCA_DEL_ARNES]
);

$uidRace = "idem-race-$run";
$raceNo  = nextInvoiceNo();
$racePayload = json_encode(salePayload($uidRace, $raceNo));

// El LOCK deja pasar el pre-check (SELECT) de los dos procesos y frena su
// INSERT en el registry: al soltarlo, los dos insertan a la vez y uno de los
// dos tiene que chocar contra la unicidad — la carrera real, no la secuencial.
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('POSTGRES_HOST'), getenv('POSTGRES_PORT') ?: '5432', getenv('POSTGRES_DB'));
$locker = new PDO($dsn, (string) getenv('POSTGRES_USER'), (string) getenv('POSTGRES_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$locker->beginTransaction();
$locker->exec('LOCK TABLE transaction_registry IN SHARE ROW EXCLUSIVE MODE');

$procs = [];
foreach ([1, 2] as $i) {
    $p = proc_open(
        [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
            __DIR__ . '/_sale_idem_once_cli.php', $racePayload],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    fclose($pipes[0]);
    $procs[] = [$p, $pipes];
}

$blocked = 0;
for ($t = 0; $t < 300; $t++) {
    usleep(100_000);
    $blocked = (int) $locker->query(
        "SELECT COUNT(*) FROM pg_stat_activity
          WHERE pid <> pg_backend_pid() AND wait_event_type = 'Lock' AND datname = current_database()"
    )->fetchColumn();
    if ($blocked >= 2) {
        break;
    }
}
$activity = $locker->query(
    "SELECT state, wait_event_type, wait_event, left(query, 80) AS q FROM pg_stat_activity
      WHERE pid <> pg_backend_pid() AND datname = current_database()"
)->fetchAll(PDO::FETCH_ASSOC);
check('(D0) setup: los DOS procesos pasaron el pre-check y quedaron frenados en el INSERT', $blocked >= 2,
    "bloqueados=$blocked actividad=" . json_encode($activity), $failures, $checks);
$locker->commit();

$outcomes = [];
foreach ($procs as [$p, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    $outcomes[] = preg_match('/RESULT:(\{.*\})/', (string) $out, $m) === 1
        ? (json_decode($m[1], true) ?? ['kind' => 'unparsed'])
        : ['kind' => 'no-result', 'out' => substr($out . $err, 0, 400)];
}
$kinds = array_map(static fn (array $o) => $o['kind'], $outcomes);
sort($kinds);
$ids = array_values(array_unique(array_filter(array_map(static fn (array $o) => $o['transactionId'] ?? null, $outcomes))));
check('(D1) un proceso crea y el otro recibe duplicated', $kinds === ['created', 'duplicate'],
    json_encode($outcomes), $failures, $checks);
check('(D2) los dos reportan el MISMO transactionId', count($ids) === 1, json_encode($outcomes), $failures, $checks);
check('(D3) UNA sola venta con ese uid', countByUid($uidRace) === 1, 'n=' . countByUid($uidRace), $failures, $checks);
$docs = (int) $db->GetOne(
    'SELECT COUNT(*) FROM einvoice_document d JOIN transaction_registry r ON r.transactionid = d.transactionid
      WHERE r.transactionuid = ?',
    [$uidRace]
);
check('(D4) UN solo einvoice_document', $docs === 1, "docs=$docs", $failures, $checks);
$db->Execute('DELETE FROM einvoice_account WHERE companyid = ? AND username = ?', [$companyId, MARCA_DEL_ARNES]);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== (E) GET /v1/sales?uid= — alcance empresa + sucursal ===\n";
$res = call('v1/sales.php', 'GET', 'uid=' . rawurlencode($uidA), [], $bearerA);
check('(E1) device de la sucursal de la venta → 200 con transactionId y número',
    $res['status'] === 200 && ($res['data']['sale']['transactionId'] ?? '') === $txA
        && (int) ($res['data']['sale']['invoiceNo'] ?? 0) === $noA,
    "status={$res['status']} {$res['body']}", $failures, $checks);

$res = call('v1/sales.php', 'GET', 'uid=' . rawurlencode($uidA), [], $bearerA2);
check('(E2) device de OTRA sucursal de la misma empresa → 404', $res['status'] === 404,
    "status={$res['status']} {$res['body']}", $failures, $checks);

$res = call('v1/sales.php', 'GET', 'uid=' . rawurlencode("idem-nunca-$run"), [], $bearerA);
check('(E3) uid inexistente → 404', $res['status'] === 404, "status={$res['status']} {$res['body']}", $failures, $checks);

$res = call('v1/sales.php', 'GET', 'uid=' . rawurlencode($uidA), [], '');
check('(E4) sin Bearer → 401 (token-only)', $res['status'] === 401, "status={$res['status']} {$res['body']}", $failures, $checks);

harnessFinish($failures, $checks);
