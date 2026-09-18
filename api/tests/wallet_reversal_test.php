<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés (Postgres REAL) de la REVERSA de cargas — context/74 §13, mig 236.
 *
 * Qué protege:
 *
 *   a. Mig 236: `load_reversal` sin la carga que revierte se rechaza en la BD,
 *      y revertir más de lo cargado también (trigger), aunque se escriba por
 *      fuera del servicio.
 *   b. Anular una venta de carga con el saldo intacto: la reversa sale en la
 *      MISMA transacción, atada a la carga, con autor y origen = la anulación;
 *      el saldo vuelve a 0.
 *   c. Anular cuando el cliente ya consumió parte: se RECHAZA (409
 *      WALLET_LOAD_USED) y NADA cambia — ni la venta queda anulada ni el saldo.
 *      `canVoid()` lo avisa antes.
 *   d. Nota de crédito PARCIAL de una carga: revierte solo esa parte; la que
 *      completa la línea revierte el resto (y el total revertido = la carga).
 *   e. Nota de crédito cuando el saldo ya se usó: rechazada entera, no nace la
 *      NC.
 *   f. Venta que cargó en DOS bolsillos: una parte se rechaza (no hay bolsillo
 *      definido); entera revierte los dos.
 *   g. La anulación legacy (tipo→7) no anula una NC que revirtió saldo.
 *   h. Carrera: un consumo en curso sobre el bolsillo (sin confirmar) y una
 *      anulación a la vez — la anulación ESPERA el lock y, cuando el consumo
 *      confirma, ve el saldo real y se rechaza.
 *   i. Reporte de bolsillos: "Cargado" neto de devoluciones y el saldo por
 *      entregar baja con cada reversa.
 *
 * Uso: bash api/tests/run_wallet_test.sh (corre después de los otros arneses
 * de wallet).
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Reports\WalletReportService;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Services\ReturnService;
use Punto\Api\Services\SaleVoidService;
use Punto\Api\Services\TransactionService;
use Punto\Api\Wallet\WalletException;
use Punto\Api\Wallet\WalletLoadAlreadyUsedException;
use Punto\Api\Wallet\WalletLoadItem;
use Punto\Api\Wallet\WalletService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

const WR_TAX10 = '3cf780bb-51d6-4b41-b52d-1e77bfb60969';

// Clientes del arnés (type=1), distintos de los otros arneses de wallet.
const RV_1 = 'a11e7000-0000-4000-8000-000000000401'; // anulación con saldo intacto
const RV_2 = 'a11e7000-0000-4000-8000-000000000402'; // anulación con saldo usado
const RV_3 = 'a11e7000-0000-4000-8000-000000000403'; // NC parcial + la que completa
const RV_4 = 'a11e7000-0000-4000-8000-000000000404'; // NC con saldo usado
const RV_5 = 'a11e7000-0000-4000-8000-000000000405'; // dos bolsillos
const RV_6 = 'a11e7000-0000-4000-8000-000000000406'; // carrera

/** @var \DB $db */
global $db;

$failures = 0;
$checks   = 0;

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

function thrown(callable $fn): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e;
    }
    return null;
}

function near(float $a, float $b, float $eps = 0.005): bool
{
    return abs($a - $b) < $eps;
}

function rvContacts(): array
{
    return [RV_1, RV_2, RV_3, RV_4, RV_5, RV_6];
}

function purgeHarness(): void
{
    global $db;
    $all = rvContacts();
    $ph  = implode(',', array_fill(0, count($all), '?'));
    $db->BeginTrans();
    $db->Execute("SELECT set_config('punto.tenant_purge', ?, true)", [$GLOBALS['companyId']]);
    $db->Execute("DELETE FROM wallet_movement WHERE companyid = ? AND contactid IN ($ph)", array_merge([$GLOBALS['companyId']], $all));
    $db->CommitTrans();
    $db->Execute("DELETE FROM wallet_pocket WHERE companyid = ? AND name LIKE 'Rev Arnés %'", [$GLOBALS['companyId']]);
}

function line(array $over): array
{
    return array_merge([
        'itemId' => '', 'count' => 1, 'name' => 'x', 'uniPrice' => 0, 'price' => 0, 'total' => 0,
        'tax' => 0, 'discount' => 0, 'totalDiscount' => 0, 'user' => '', 'type' => '', 'date' => '',
        'note' => '', 'currency' => '', 'uId' => 0,
    ], $over);
}

/** Venta de CARGA (contado) del POS: una línea `walletLoad` por [pocketId, monto]. */
function loadSale(SaleService $service, string $companyId, int &$nextNo, string $client, array $loads): string
{
    $lines = [];
    foreach ($loads as [$pocketId, $amount]) {
        $lines[] = line(['name' => 'Carga', 'uniPrice' => $amount, 'price' => $amount, 'total' => $amount, 'walletLoad' => ['pocketId' => $pocketId]]);
    }
    $subtotal = array_sum(array_column($loads, 1));
    $payload  = ['transaction' => [
        'uid' => 'wrev-' . bin2hex(random_bytes(5)), 'type' => 0, 'invoiceno' => $nextNo++, 'sale' => $lines,
        'subtotal' => $subtotal, 'tax' => 0, 'discount' => 0,
        'payment' => [['type' => 'cash', 'name' => 'Efectivo', 'total' => $subtotal]],
        'date' => date('Y-m-d H:i:s'), 'timestamp' => time(), 'client' => $client,
    ]];
    return (string) $service->save(SaleInput::fromPayload($payload, $companyId))->transactionId;
}

/** Un intento de `SaleVoidService::void()` en subproceso (apiConflict hace exit). */
function voidInSubprocess(string $saleId, string $reason): string
{
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $cmd = escapeshellarg($php) . ' -d variables_order=EGPCS ' . escapeshellarg(__DIR__ . '/_sale_void_once_cli.php')
        . ' ' . escapeshellarg($GLOBALS['companyId']) . ' ' . escapeshellarg($saleId) . ' ' . escapeshellarg($GLOBALS['adminId'])
        . ' ' . escapeshellarg($GLOBALS['registerId']) . ' ' . escapeshellarg($GLOBALS['outletId']) . ' ' . escapeshellarg($reason) . ' 2>&1';
    return (string) (shell_exec($cmd) ?? '');
}

function reversalsOf(string $saleId): array
{
    global $db;
    $rs = $db->Execute(
        "SELECT r.amount, r.sourcetype, r.sourceid, r.reversesmovementid, r.actorcontactid, r.pocketid
           FROM wallet_movement r
           JOIN wallet_movement l ON l.id = r.reversesmovementid
          WHERE r.type = 'load_reversal' AND l.sourceid = ?
          ORDER BY r.seq",
        [$saleId]
    );
    return $rs ? $rs->GetRows() : [];
}

function loadIdsOf(string $saleId): array
{
    $rs = $GLOBALS['db']->Execute("SELECT id FROM wallet_movement WHERE type = 'load' AND sourceid = ? ORDER BY seq", [$saleId]);
    return $rs ? array_column($rs->GetRows(), 'id') : [];
}

function isVoided(string $saleId): bool
{
    $r = ncmExecute('SELECT voidedat FROM transaction WHERE transactionid = ?', [$saleId]);
    return !empty($r['voidedat']);
}

$ctx     = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$service = new SaleService($ctx, $db);
$wallet  = new WalletService();
$returns = new ReturnService();
$report  = new WalletReportService();
$modules = new \Punto\Api\Modules\ModulesService();
$nextNo  = 800000 + random_int(1, 90000);

foreach (rvContacts() as $i => $cid) {
    $db->Execute(
        'INSERT INTO contact (contactId, contactName, companyId, type, contactStatus)
         VALUES (?, ?, ?, 1, 1)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName, contactStatus = 1, parentcontactid = NULL',
        [$cid, 'Reversa ' . ($i + 1), $companyId]
    );
}
purgeHarness();
$modules->toggle($companyId, 'wallet', true);

$from   = date('Y-m-d') . ' 00:00:00';
$to     = date('Y-m-d') . ' 23:59:59';
$rocAll = " AND t.companyId = '{$companyId}'";
$s0     = $report->summary($from, $to, $rocAll, $companyId);

try {
    $pA = $wallet->createPocket($companyId, 'Rev Arnés Almuerzo', WR_TAX10);
    $pB = $wallet->createPocket($companyId, 'Rev Arnés Merienda', WR_TAX10);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (a) mig 236: la BD sostiene la reversa aunque se escriba por fuera ===\n";
    $saleA = loadSale($service, $companyId, $nextNo, RV_1, [[$pA['id'], 1000]]);
    $loadA = loadIdsOf($saleA)[0] ?? '';
    $bal   = $wallet->balance($companyId, RV_1, $pA['id']);
    $e = thrown(fn () => $db->Execute(
        "INSERT INTO wallet_movement (companyid, contactid, pocketid, type, amount, balanceafter, sourcetype, sourceid, actorcontactid)
         VALUES (?, ?, ?, 'load_reversal', -100, ?, 'return', ?, ?)",
        [$companyId, RV_1, $pA['id'], $bal - 100, $saleA, $adminId]
    ));
    check('(a1) load_reversal sin la carga que revierte: rechazado', $e !== null, 'entró', $failures, $checks);
    $e = thrown(fn () => $db->Execute(
        "INSERT INTO wallet_movement (companyid, contactid, pocketid, type, amount, balanceafter, sourcetype, sourceid, reversesmovementid, actorcontactid)
         VALUES (?, ?, ?, 'load_reversal', -1500, ?, 'return', ?, ?, ?)",
        [$companyId, RV_1, $pA['id'], $bal - 1500, $saleA, $loadA, $adminId]
    ));
    check('(a2) revertir más de lo cargado: rechazado por el trigger', $e !== null && str_contains($e->getMessage(), 'wallet_load_reversal'),
        (string) $e?->getMessage(), $failures, $checks);
    check('(a3) y el saldo no se movió', near($wallet->balance($companyId, RV_1, $pA['id']), $bal),
        (string) $wallet->balance($companyId, RV_1, $pA['id']), $failures, $checks);
    // Se vacía el bolsillo para que (b) arranque en 0.
    $out = voidInSubprocess($saleA, 'limpieza (a)');
    check('(a4) anular esa carga deja el bolsillo en 0', str_contains($out, '"ok":true') && near($wallet->balance($companyId, RV_1, $pA['id']), 0),
        $out, $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (b) anular una carga con el saldo intacto revierte ===\n";
    $saleB = loadSale($service, $companyId, $nextNo, RV_1, [[$pA['id'], 50000]]);
    check('(b1) cargado: saldo 50.000', near($wallet->balance($companyId, RV_1, $pA['id']), 50000),
        (string) $wallet->balance($companyId, RV_1, $pA['id']), $failures, $checks);
    $can = (new SaleVoidService())->canVoid($companyId, $saleB);
    check('(b2) canVoid lo permite', $can['allowed'] === true, json_encode($can), $failures, $checks);
    $sBeforeVoid = $report->summary($from, $to, $rocAll, $companyId);
    $out = voidInSubprocess($saleB, 'cliente se arrepintió');
    check('(b3) la anulación confirma', str_contains($out, '"ok":true') && isVoided($saleB), $out, $failures, $checks);
    check('(b4) el saldo vuelve a 0', near($wallet->balance($companyId, RV_1, $pA['id']), 0),
        (string) $wallet->balance($companyId, RV_1, $pA['id']), $failures, $checks);
    $rv = reversalsOf($saleB);
    check('(b5) una reversa de −50.000, origen = la anulación, atada a la carga, con autor',
        count($rv) === 1 && near((float) $rv[0]['amount'], -50000) && $rv[0]['sourcetype'] === 'sale_void'
        && $rv[0]['sourceid'] === $saleB && $rv[0]['reversesmovementid'] === (loadIdsOf($saleB)[0] ?? '')
        && $rv[0]['actorcontactid'] === $adminId,
        json_encode($rv), $failures, $checks);
    $sAfterVoid = $report->summary($from, $to, $rocAll, $companyId);
    check('(b6) reporte: la carga anulada sale de "Cargado" y el saldo por entregar baja 50.000',
        near($sBeforeVoid['loaded'] - $sAfterVoid['loaded'], 50000) && near($sBeforeVoid['liability'] - $sAfterVoid['liability'], 50000),
        json_encode([$sBeforeVoid, $sAfterVoid]), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (c) anular cuando el cliente ya usó parte: rechazo y nada cambia ===\n";
    $saleC = loadSale($service, $companyId, $nextNo, RV_2, [[$pA['id'], 40000]]);
    $wallet->spend($companyId, RV_2, $pA['id'], 10000, 'harness', null, $adminId);
    $can = (new SaleVoidService())->canVoid($companyId, $saleC);
    check('(c1) canVoid lo niega con el motivo', $can['allowed'] === false && str_contains((string) $can['reason'], 'ya usó parte'),
        json_encode($can), $failures, $checks);
    $finBefore = ncmExecute("SELECT COUNT(*) AS n FROM fin_movement WHERE companyid = ? AND source = 'sale' AND sourceid = ? AND status = 1", [$companyId, $saleC]);
    $out = voidInSubprocess($saleC, 'intento con saldo usado');
    check('(c2) la anulación se rechaza con 409 WALLET_LOAD_USED y el motivo',
        str_contains($out, '"ok":false') && str_contains($out, 'WALLET_LOAD_USED') && str_contains($out, 'ya usó parte'),
        $out, $failures, $checks);
    check('(c3) con lo disponible y lo requerido', str_contains($out, '"available":30000') && str_contains($out, '"required":40000'),
        $out, $failures, $checks);
    check('(c4) la venta NO quedó anulada', !isVoided($saleC), 'quedó anulada', $failures, $checks);
    check('(c5) el saldo sigue en 30.000 y no hay reversa', near($wallet->balance($companyId, RV_2, $pA['id']), 30000) && reversalsOf($saleC) === [],
        $wallet->balance($companyId, RV_2, $pA['id']) . ' ' . json_encode(reversalsOf($saleC)), $failures, $checks);
    $finAfter = ncmExecute("SELECT COUNT(*) AS n FROM fin_movement WHERE companyid = ? AND source = 'sale' AND sourceid = ? AND status = 1", [$companyId, $saleC]);
    check('(c6) ni la caja se revirtió', (int) ($finAfter['n'] ?? -1) === (int) ($finBefore['n'] ?? -2),
        json_encode([$finBefore, $finAfter]), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (d) nota de crédito parcial de una carga ===\n";
    $loadItem = WalletLoadItem::find($companyId);
    $saleD = loadSale($service, $companyId, $nextNo, RV_3, [[$pA['id'], 60000]]);
    $sBeforeNc = $report->summary($from, $to, $rocAll, $companyId);
    $nc1 = $returns->create($companyId, $adminId, $outletId, $registerId, $saleD, [['itemId' => $loadItem, 'qty' => 0.5]], 'cash', 'mitad');
    check('(d1) la NC devuelve 30.000', near((float) $nc1['total'], 30000), json_encode($nc1), $failures, $checks);
    check('(d2) el bolsillo queda en 30.000', near($wallet->balance($companyId, RV_3, $pA['id']), 30000),
        (string) $wallet->balance($companyId, RV_3, $pA['id']), $failures, $checks);
    $rv = reversalsOf($saleD);
    check('(d3) reversa de −30.000 con origen = la nota de crédito', count($rv) === 1 && near((float) $rv[0]['amount'], -30000)
        && $rv[0]['sourcetype'] === 'return' && $rv[0]['sourceid'] === $nc1['id'], json_encode($rv), $failures, $checks);
    $sAfterNc = $report->summary($from, $to, $rocAll, $companyId);
    check('(d4) reporte: "Cargado" baja lo devuelto y el saldo por entregar también',
        near($sBeforeNc['loaded'] - $sAfterNc['loaded'], 30000) && near($sBeforeNc['liability'] - $sAfterNc['liability'], 30000),
        json_encode([$sBeforeNc, $sAfterNc]), $failures, $checks);
    $byPocket = array_values(array_filter($report->full($from, $to, $rocAll, $companyId)['byPocket'], fn ($p) => $p['pocketId'] === $pA['id']));
    check('(d5) reporte por bolsillo: cargado neto', ($byPocket[0]['loaded'] ?? null) !== null, json_encode($byPocket), $failures, $checks);

    $nc2 = $returns->create($companyId, $adminId, $outletId, $registerId, $saleD, [['itemId' => $loadItem, 'qty' => 0.5]], 'cash', 'la otra mitad');
    check('(d6) la NC que completa la línea deja el bolsillo en 0', near($wallet->balance($companyId, RV_3, $pA['id']), 0),
        (string) $wallet->balance($companyId, RV_3, $pA['id']), $failures, $checks);
    $sumRv = array_sum(array_map(fn ($r) => -(float) $r['amount'], reversalsOf($saleD)));
    check('(d7) lo revertido suma exactamente la carga', near($sumRv, 60000) && count(reversalsOf($saleD)) === 2,
        json_encode(reversalsOf($saleD)), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (e) nota de crédito cuando el saldo ya se usó ===\n";
    $saleE = loadSale($service, $companyId, $nextNo, RV_4, [[$pA['id'], 20000]]);
    $wallet->spend($companyId, RV_4, $pA['id'], 15000, 'harness', null, $adminId);
    $countNc = fn () => (int) (ncmExecute(
        "SELECT COUNT(*) AS n FROM transaction_link WHERE companyid = ? AND originid = ? AND kind = 'return'",
        [$companyId, $saleE]
    )['n'] ?? -1);
    $e = thrown(fn () => $returns->create($companyId, $adminId, $outletId, $registerId, $saleE, [['itemId' => $loadItem, 'qty' => 1]], 'cash', 'toda'));
    check('(e1) rechazada con WalletLoadAlreadyUsedException', $e instanceof WalletLoadAlreadyUsedException
        && near($e->available, 5000) && near($e->required, 20000), get_class($e ?? new \stdClass()) . ' ' . (string) $e?->getMessage(), $failures, $checks);
    check('(e2) no nació la nota de crédito', $countNc() === 0, 'n=' . $countNc(), $failures, $checks);
    check('(e3) el saldo sigue en 5.000', near($wallet->balance($companyId, RV_4, $pA['id']), 5000),
        (string) $wallet->balance($companyId, RV_4, $pA['id']), $failures, $checks);
    $e = thrown(fn () => $returns->create($companyId, $adminId, $outletId, $registerId, $saleE, [['itemId' => $loadItem, 'qty' => 0.25]], 'cash', 'un cuarto'));
    check('(e4) una parte que SÍ alcanza (5.000) se devuelve', $e === null && near($wallet->balance($companyId, RV_4, $pA['id']), 0),
        (string) $e?->getMessage() . ' saldo=' . $wallet->balance($companyId, RV_4, $pA['id']), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (f) venta que cargó en dos bolsillos ===\n";
    $saleF = loadSale($service, $companyId, $nextNo, RV_5, [[$pA['id'], 10000], [$pB['id'], 5000]]);
    $e = thrown(fn () => $returns->create($companyId, $adminId, $outletId, $registerId, $saleF, [['itemId' => $loadItem, 'qty' => 1]], 'cash', 'una'));
    check('(f1) una parte se rechaza: la carga se devuelve entera', $e instanceof WalletException && str_contains($e->getMessage(), 'más de un bolsillo'),
        (string) $e?->getMessage(), $failures, $checks);
    check('(f2) y nada cambió', near($wallet->balance($companyId, RV_5, $pA['id']), 10000) && near($wallet->balance($companyId, RV_5, $pB['id']), 5000),
        'saldos movidos', $failures, $checks);
    $ncF = $returns->create($companyId, $adminId, $outletId, $registerId, $saleF, [['itemId' => $loadItem, 'qty' => 2]], 'cash', 'entera');
    check('(f3) entera revierte los dos bolsillos', near($wallet->balance($companyId, RV_5, $pA['id']), 0) && near($wallet->balance($companyId, RV_5, $pB['id']), 0)
        && count(reversalsOf($saleF)) === 2, json_encode(reversalsOf($saleF)), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (g) la anulación legacy no anula una NC que revirtió saldo ===\n";
    $e = thrown(fn () => (new TransactionService($ctx, $db))->voidTransaction((string) $ncF['id'], $companyId, $outletId, $adminId, 'legacy'));
    $type = ncmExecute('SELECT transactiontype FROM transaction WHERE transactionid = ?', [$ncF['id']]);
    check('(g1) rechazada con motivo y la NC sigue vigente (tipo 6)', $e instanceof WalletException && (int) ($type['transactiontype'] ?? -1) === 6,
        (string) $e?->getMessage() . ' ' . json_encode($type), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (h) carrera: consumo en curso y anulación a la vez ===\n";
    $saleH = loadSale($service, $companyId, $nextNo, RV_6, [[$pA['id'], 50000]]);
    $php   = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $spendCmd = [$php, '-d', 'variables_order=EGPCS', __DIR__ . '/_wallet_spend_once_cli.php',
        $companyId, RV_6, $pA['id'], '20000', $adminId, '3'];
    $proc = proc_open($spendCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    // Esperar a que el consumo haya debitado y tenga el lock (imprime "spent").
    $first = fgets($pipes[1]);
    $t0    = microtime(true);
    $out   = voidInSubprocess($saleH, 'carrera');
    $waited = microtime(true) - $t0;
    $rest  = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    check('(h1) el consumo debitó y confirmó', trim((string) $first) === 'spent' && str_contains($rest, 'committed'),
        "first=$first rest=$rest", $failures, $checks);
    check('(h2) la anulación esperó el lock del bolsillo (≥ 1,5 s)', $waited >= 1.5, "esperó {$waited}s", $failures, $checks);
    check('(h3) y, con el saldo real, se rechazó', str_contains($out, 'WALLET_LOAD_USED') && !isVoided($saleH), $out, $failures, $checks);
    check('(h4) saldo = 30.000, nunca negativo', near($wallet->balance($companyId, RV_6, $pA['id']), 30000),
        (string) $wallet->balance($companyId, RV_6, $pA['id']), $failures, $checks);
    $chain = ncmExecute(
        'SELECT COUNT(*) AS n FROM wallet_movement WHERE companyid = ? AND contactid = ? AND balanceafter < 0',
        [$companyId, RV_6]
    );
    check('(h5) ningún movimiento negativo', (int) ($chain['n'] ?? -1) === 0, json_encode($chain), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (i) reporte: el período cuadra ===\n";
    $s1 = $report->summary($from, $to, $rocAll, $companyId);
    // Cargas que quedan en pie: (c) 40.000, (e) 20.000 − 5.000 devueltos,
    // (h) 50.000. Anuladas (a, b) y devueltas enteras (d, f) netean cero.
    check('(i1) "Cargado" del arnés = 105.000 (neto de anulaciones y devoluciones)', near($s1['loaded'] - $s0['loaded'], 105000),
        json_encode([$s0['loaded'], $s1['loaded']]), $failures, $checks);
    // Saldo que queda: (c) 30.000 + (h) 30.000; el resto en 0.
    check('(i2) saldo por entregar del arnés = 60.000', near($s1['liability'] - $s0['liability'], 60000),
        json_encode([$s0['liability'], $s1['liability']]), $failures, $checks);
} finally {
    purgeHarness();
}

harnessFinish($failures, $checks);
