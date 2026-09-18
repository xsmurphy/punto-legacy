<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés (Postgres REAL) del reporte de BOLSILLOS — context/74 §13.
 *
 * Qué protege:
 *
 *   a. Mig 235 + `SaleService::freezeWalletListTotals()`: el consumo con saldo
 *      congela el valor de LISTA de cada línea resuelto en el SERVIDOR (lista
 *      del cliente → de la sucursal → precio del ítem), no el que manda la
 *      caja. Una venta normal no lo escribe.
 *   b. KPIs sobre un set conocido: cargado, consumido (= débito real),
 *      diferencias con y sin descuento registrado, período anterior vacío.
 *   c. Diferencias: un consumo con precio bajado y uno con total declarado
 *      menor que sus líneas aparecen SIN descuento, con su usuario (el
 *      operador del PIN) y su caja; uno con descuento registrado se separa;
 *      un consumo a precio de lista (incluida la lista del cliente) no aparece.
 *   d. Productos consumidos, por bolsillo y día a día.
 *   e. Saldo vigente = suma de los saldos de TODO el comercio.
 *   f. Endpoint real: un usuario acotado a una sucursal no ve el consumo de
 *      otra; uno global sí; con el módulo apagado, 403.
 *
 * Los documentos van a un día aislado del pasado (WR_DAY, al azar por corrida) para que los datos de
 * los arneses anteriores del mismo run no ensucien las cuentas.
 *
 * Uso: bash api/tests/run_wallet_test.sh (corre después de wallet_pos_test.php).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/includes/auth_session.php';
require_once dirname(__DIR__) . '/lib/Auth/RoleService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Reports\Roc;
use Punto\Api\Reports\WalletReportService;
use Punto\Api\Sales\SaleInput;
use Punto\Api\Sales\SaleService;
use Punto\Api\Support\TenantClock;
use Punto\Api\Wallet\WalletService;

// ── Tenant fixture "Verify PY" (api/lib/Sales/verify_chain/seed.sql) ───────
$companyId  = '0ea6c5d8-57e5-4226-8140-ec914deec024';
$outletId   = '1a282724-6073-49c3-8bc3-0114a132e349';
$registerId = '81c541da-640e-4891-a1a0-b32841e64c75';
$adminId    = '3e52da17-74a2-49c3-9d07-8d4806671fd5';
$userId     = $adminId;
$roleId     = '1';
require API_APP_DIR . '/data.php';

const WR_TAX10  = '3cf780bb-51d6-4b41-b52d-1e77bfb60969';
const WR_ITEM   = '10223f3b-2e3d-4339-8496-9f288d8be65b'; // sin stock, precio 11.000
// Día aislado del pasado, distinto en cada corrida: contra una base reusada
// (WALLET_ALLOW_EXISTING_DB=1) los documentos de una corrida anterior no
// pueden caer en el mismo día y duplicar las cuentas.
$wrDay = (new \DateTimeImmutable('2020-01-01'))->modify('+' . random_int(1, 1800) . ' days');
define('WR_DAY', $wrDay->format('Y-m-d'));
define('WR_PREV', $wrDay->modify('-1 day')->format('Y-m-d'));
const WR_MARCA  = 'wallet-report-test';

const WR_OUTLET_B   = 'a11e7000-0000-4000-8000-0000000004b1';
const WR_REGISTER_B = 'a11e7000-0000-4000-8000-0000000004b2';
const WR_T1   = 'a11e7000-0000-4000-8000-000000000401'; // titular sin lista
const WR_T2   = 'a11e7000-0000-4000-8000-000000000402'; // titular con lista −10%
const WR_OPX  = 'a11e7000-0000-4000-8000-000000000411'; // operador X
const WR_OPY  = 'a11e7000-0000-4000-8000-000000000412'; // operador Y
const WR_USA  = 'a11e7000-0000-4000-8000-000000000421'; // panel, solo sucursal A
const WR_USG  = 'a11e7000-0000-4000-8000-000000000422'; // panel, global

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

function near(float $a, float $b, float $eps = 0.005): bool
{
    return abs($a - $b) < $eps;
}

function wuid(string $tag): string
{
    return 'wrep-' . $tag . '-' . bin2hex(random_bytes(5));
}

function wline(array $over): array
{
    return array_merge([
        'itemId' => '', 'count' => 1, 'name' => 'x', 'uniPrice' => 0, 'price' => 0, 'total' => 0,
        'tax' => 0, 'discount' => 0, 'totalDiscount' => 0, 'user' => '', 'type' => '', 'date' => '',
        'note' => '', 'currency' => '', 'uId' => 0,
    ], $over);
}

/** Fecha del DÍA aislado; `timestamp` 0 hace que mande `date` (SaleInput::resolveDate). */
function wdate(string $hour): string
{
    return WR_DAY . ' ' . $hour;
}

function purgeReportHarness(): void
{
    global $db;
    $all = [WR_T1, WR_T2];
    $db->BeginTrans();
    $db->Execute("SELECT set_config('punto.tenant_purge', ?, true)", [$GLOBALS['companyId']]);
    $db->Execute('DELETE FROM wallet_movement WHERE companyid = ? AND contactid IN (?, ?)', array_merge([$GLOBALS['companyId']], $all));
    $db->CommitTrans();
    $db->Execute("DELETE FROM wallet_pocket WHERE companyid = ? AND name LIKE 'Reporte Arnés %'", [$GLOBALS['companyId']]);
    $db->Execute("UPDATE contact SET data = COALESCE(data, '{}'::jsonb) - 'priceListId' WHERE contactid = ?", [WR_T2]);
    $db->Execute("DELETE FROM price_list WHERE companyid = ? AND pricelistname = 'Reporte Arnés −10'", [$GLOBALS['companyId']]);
}

/** Endpoint real en subproceso (apiError hace exit; el alcance vive en constantes). */
function wcall(string $query, string $bearer, ?string $outletHeader = null): array
{
    $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE',
            __DIR__ . '/_permission_once_cli.php', 'v1/reports/wallet.php', 'GET', $query, '{}', '', $bearer, '', ''];
    // `X-Outlet-Id` del selector del logo del panel: bajo CLI los headers
    // llegan a `$_SERVER` desde el entorno (variables_order=EGPCS).
    $env = getenv();
    if ($outletHeader !== null) {
        $env['HTTP_X_OUTLET_ID'] = $outletHeader;
    }
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
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
    return ['status' => $status, 'data' => $data, 'body' => substr($out . $err, 0, 800)];
}

TenantClock::apply($companyId);

$ctxA    = TenantContext::fromAuth(compact('companyId', 'outletId', 'userId', 'registerId', 'roleId'));
$ctxB    = TenantContext::fromAuth(['companyId' => $companyId, 'outletId' => WR_OUTLET_B, 'userId' => $userId, 'registerId' => WR_REGISTER_B, 'roleId' => $roleId]);
$saleA   = new SaleService($ctxA, $db);
$saleB   = new SaleService($ctxB, $db);
$wallet  = new WalletService();
$report  = new WalletReportService();
$modules = new \Punto\Api\Modules\ModulesService();
$nextNo  = 800000 + random_int(1, 90000);

// ── Fixture ──────────────────────────────────────────────────────────────────
\RoleService::seedCompanyRoles($companyId);
$ownerRole = (string) (ncmExecute(
    "SELECT taxonomyid FROM taxonomy WHERE taxonomytype='role' AND companyid=? AND taxonomyextra::json->>'slug'='owner'",
    [$companyId]
)['taxonomyid'] ?? '');

$db->Execute(
    'INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletStatus = 1',
    [WR_OUTLET_B, 'Sucursal B reporte arnés', $companyId]
);
$db->Execute(
    'INSERT INTO register (registerid, registername, registerstatus, registerinvoicenumber, registerticketnumber,
                           registerreturnnumber, registerschedulenumber, registerpedidonumber, registerquotenumber,
                           outletid, companyid)
     VALUES (?, ?, TRUE, 1, 1, 1, 1, 1, 1, ?, ?)
     ON CONFLICT (registerid) DO UPDATE SET registername = EXCLUDED.registername',
    [WR_REGISTER_B, 'Caja B reporte arnés', WR_OUTLET_B, $companyId]
);
foreach ([[WR_T1, 'Reporte Titular Uno', 1, null], [WR_T2, 'Reporte Titular Dos', 1, null],
          [WR_OPX, 'Reporte Operador X', 0, $ownerRole], [WR_OPY, 'Reporte Operador Y', 0, $ownerRole],
          [WR_USA, 'Reporte Panel A', 0, $ownerRole], [WR_USG, 'Reporte Panel Global', 0, $ownerRole]] as [$cid, $name, $type, $role]) {
    $db->Execute(
        'INSERT INTO contact (contactId, contactName, contactEmail, companyId, type, contactStatus, role)
         VALUES (?, ?, ?, ?, ?, 1, ?)
         ON CONFLICT (contactId) DO UPDATE SET contactName = EXCLUDED.contactName, contactStatus = 1, role = EXCLUDED.role',
        [$cid, $name, strtolower(str_replace(' ', '', $name)) . '@wrep.local', $companyId, $type, $role]
    );
}
$db->Execute('DELETE FROM contact_outlet WHERE contactid IN (?::uuid, ?::uuid)', [WR_USA, WR_USG]);
$db->Execute('INSERT INTO contact_outlet (contactid, outletid, companyid) VALUES (?::uuid, ?::uuid, ?::uuid) ON CONFLICT DO NOTHING',
    [WR_USA, $outletId, $companyId]);

purgeReportHarness();
$modules->toggle($companyId, 'wallet', true);

$listRow = $db->Execute(
    "INSERT INTO price_list (pricelistname, defaultadjustment, status, companyid)
     VALUES ('Reporte Arnés −10', -10, TRUE, ?) RETURNING pricelistid",
    [$companyId]
);
$priceListId = (string) ($listRow->fields['pricelistid'] ?? '');
$db->Execute("UPDATE contact SET data = jsonb_set(COALESCE(data, '{}'::jsonb), '{priceListId}', to_jsonb(?::text)) WHERE contactid = ?",
    [$priceListId, WR_T2]);

try {
    $pocket = $wallet->createPocket($companyId, 'Reporte Arnés Almuerzo', WR_TAX10);
    $pid    = $pocket['id'];

    // ── Cargas (venta tipo 0, sucursal A) ───────────────────────────────────
    $load = function (string $client, float $amount, string $hour) use ($saleA, $companyId, $pid, &$nextNo): string {
        return $saleA->save(SaleInput::fromPayload(['transaction' => [
            'uid' => wuid('load'), 'type' => 0, 'invoiceno' => $nextNo++,
            'sale' => [wline(['name' => 'Carga', 'uniPrice' => $amount, 'price' => $amount, 'total' => $amount, 'walletLoad' => ['pocketId' => $pid]])],
            'subtotal' => $amount, 'tax' => 0, 'discount' => 0,
            'payment' => [['type' => 'cash', 'name' => 'Efectivo', 'total' => $amount]],
            'date' => wdate($hour), 'timestamp' => 0, 'client' => $client,
        ]], $companyId))->transactionId;
    };
    $load(WR_T1, 100000, '08:00:00');
    $load(WR_T2, 20000, '08:05:00');

    // ── Consumos ────────────────────────────────────────────────────────────
    // $subtotal null = suma de las líneas (lo normal); un número = total declarado.
    $consume = function (SaleService $svc, string $client, string $op, array $lines, string $hour, ?float $subtotal = null)
        use ($companyId, $pocket): string {
        $raw = [
            'uid' => wuid('cons'), 'client' => $client, 'sale' => $lines,
            'subtotal' => $subtotal ?? array_sum(array_map(fn ($l) => (float) $l['total'], $lines)),
            'discount' => array_sum(array_map(fn ($l) => (float) $l['totalDiscount'], $lines)),
            'timestamp' => 0, 'date' => wdate($hour),
        ];
        return $svc->save(SaleInput::forWalletConsumption($raw, $companyId, $pocket, $op))->transactionId;
    };
    $item = fn (float $price, float $count = 1, float $disc = 0) => wline([
        'itemId' => WR_ITEM, 'count' => $count, 'uniPrice' => $price, 'price' => $price,
        'total' => $price * $count, 'totalDiscount' => $disc,
    ]);

    $c1 = $consume($saleA, WR_T1, WR_OPX, [$item(11000)], '10:00:00');             // a lista: sin diferencia
    $c2 = $consume($saleA, WR_T1, WR_OPX, [$item(9000, 2)], '10:10:00');           // precio bajado: 4.000 sin descuento
    $c3 = $consume($saleA, WR_T1, WR_OPX, [$item(11000, 1, 1000)], '10:20:00');    // descuento registrado 1.000
    $c4 = $consume($saleB, WR_T1, WR_OPY, [$item(5000)], '10:30:00');              // sucursal B: 6.000 sin descuento
    $c5 = $consume($saleA, WR_T1, WR_OPY, [$item(11000)], '10:40:00', 7000);       // total declarado < líneas: 4.000
    $c6 = $consume($saleA, WR_T2, WR_OPX, [$item(9900)], '10:50:00');              // lista del cliente −10%: sin diferencia

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (a) valor de lista congelado en la línea ===\n";
    $lt = fn (string $tid) => ncmExecute('SELECT SUM(itemsoldlisttotal) AS l, COUNT(*) FILTER (WHERE itemsoldlisttotal IS NULL) AS n FROM itemsold WHERE transactionid = ?', [$tid]);
    $r = $lt($c2);
    check('(a1) precio bajado en la caja: la lista sale del catálogo (2 × 11.000)', near((float) $r['l'], 22000) && (int) $r['n'] === 0, json_encode($r), $failures, $checks);
    $r = $lt($c6);
    check('(a2) cliente con lista −10%: la lista es la del cliente (9.900)', near((float) $r['l'], 9900), json_encode($r), $failures, $checks);
    $r = $lt($c5);
    check('(a3) total declarado menor: la línea igual congela 11.000', near((float) $r['l'], 11000), json_encode($r), $failures, $checks);
    $loadTx = ncmExecute(
        "SELECT COUNT(*) FILTER (WHERE i.itemsoldlisttotal IS NOT NULL) AS n FROM itemsold i
           JOIN transaction t ON t.transactionid = i.transactionid
          WHERE t.companyid = ? AND t.transactiontype = 0 AND t.transactiondate BETWEEN ? AND ?",
        [$companyId, WR_DAY . ' 00:00:00', WR_DAY . ' 23:59:59']
    );
    check('(a4) una venta normal (la carga) no congela lista', (int) ($loadTx['n'] ?? -1) === 0, json_encode($loadTx), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (b) KPIs sobre el set conocido ===\n";
    $from   = WR_DAY . ' 00:00:00';
    $to     = WR_DAY . ' 23:59:59';
    $rocAll = " AND t.companyId = '{$companyId}'";
    $rocA   = Roc::build($companyId, $outletId, 't');
    $s      = $report->summary($from, $to, $rocAll, $companyId);
    check('(b1) cargado = 120.000', near($s['loaded'], 120000), json_encode($s), $failures, $checks);
    check('(b2) consumido = débitos (11.000+18.000+10.000+5.000+7.000+9.900 = 60.900)', near($s['consumed'], 60900) && $s['consumptions'] === 6,
        json_encode($s), $failures, $checks);
    check('(b3) diferencias sin descuento = 14.000 en 3 consumos', near($s['differences']['unexplained'], 14000) && $s['differences']['unexplainedCount'] === 3,
        json_encode($s['differences']), $failures, $checks);
    check('(b4) con descuento registrado = 1.000; 4 consumos con diferencia', near($s['differences']['withDiscount'], 1000) && $s['differences']['count'] === 4,
        json_encode($s['differences']), $failures, $checks);
    $sA = $report->summary($from, $to, $rocA, $companyId);
    check('(b5) acotado a la sucursal A: sin el consumo de B (55.900, 8.000)', near($sA['consumed'], 55900) && near($sA['differences']['unexplained'], 8000),
        json_encode($sA), $failures, $checks);
    $sP = $report->summary(WR_PREV . ' 00:00:00', WR_PREV . ' 23:59:59', $rocAll, $companyId);
    check('(b6) el período anterior viene vacío', near($sP['loaded'], 0) && near($sP['consumed'], 0) && $sP['differences']['count'] === 0,
        json_encode($sP), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (c) control de diferencias ===\n";
    $full = $report->full($from, $to, $rocAll, $companyId);
    $rows = [];
    foreach ($full['differences']['rows'] as $row) {
        $rows[$row['transactionId']] = $row;
    }
    check('(c1) a precio de lista (propia o del cliente) no aparecen', !isset($rows[$c1]) && !isset($rows[$c6]), json_encode(array_keys($rows)), $failures, $checks);
    check('(c2) precio bajado: 4.000 sin descuento, con su usuario y su caja',
        isset($rows[$c2]) && near($rows[$c2]['unexplained'], 4000) && near($rows[$c2]['discount'], 0)
        && $rows[$c2]['userName'] === 'Reporte Operador X' && $rows[$c2]['registerName'] === 'Verify PY - Caja'
        && near($rows[$c2]['listValue'], 22000) && near($rows[$c2]['charged'], 18000),
        json_encode($rows[$c2] ?? null), $failures, $checks);
    check('(c3) descuento registrado: se separa (1.000 con descuento, 0 sin)',
        isset($rows[$c3]) && near($rows[$c3]['discount'], 1000) && near($rows[$c3]['unexplained'], 0), json_encode($rows[$c3] ?? null), $failures, $checks);
    check('(c4) total declarado menor que las líneas: 4.000 sin descuento',
        isset($rows[$c5]) && near($rows[$c5]['unexplained'], 4000) && $rows[$c5]['userName'] === 'Reporte Operador Y', json_encode($rows[$c5] ?? null), $failures, $checks);
    check('(c5) el de la sucursal B, con su caja', isset($rows[$c4]) && $rows[$c4]['registerName'] === 'Caja B reporte arnés' && near($rows[$c4]['unexplained'], 6000),
        json_encode($rows[$c4] ?? null), $failures, $checks);
    $byUser = [];
    foreach ($full['differences']['byUser'] as $g) {
        $byUser[$g['id']] = $g;
    }
    check('(c6) por usuario: X = 4.000 sin + 1.000 con; Y = 10.000 sin',
        near($byUser[WR_OPX]['unexplained'] ?? -1, 4000) && near($byUser[WR_OPX]['discount'] ?? -1, 1000)
        && near($byUser[WR_OPY]['unexplained'] ?? -1, 10000) && ($byUser[WR_OPY]['count'] ?? 0) === 2,
        json_encode($full['differences']['byUser']), $failures, $checks);
    $byReg = [];
    foreach ($full['differences']['byRegister'] as $g) {
        $byReg[$g['id']] = $g;
    }
    check('(c7) por caja: A = 8.000 sin, B = 6.000 sin', near($byReg[$registerId]['unexplained'] ?? -1, 8000) && near($byReg[WR_REGISTER_B]['unexplained'] ?? -1, 6000),
        json_encode($full['differences']['byRegister']), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (d) productos, bolsillos y día a día ===\n";
    $prod = array_values(array_filter($full['byProduct'], fn ($p) => $p['itemId'] === WR_ITEM));
    check('(d1) producto consumido: 7 unidades, valor neto de las líneas 64.900',
        count($prod) === 1 && near($prod[0]['units'], 7) && near($prod[0]['value'], 64900), json_encode($full['byProduct']), $failures, $checks);
    $pk = array_values(array_filter($full['byPocket'], fn ($p) => $p['pocketId'] === $pid));
    check('(d2) por bolsillo: cargado 120.000, consumido 60.900', count($pk) === 1 && near($pk[0]['loaded'], 120000) && near($pk[0]['consumed'], 60900),
        json_encode($pk), $failures, $checks);
    check('(d3) día a día: un punto por día con lo del día', count($full['byDay']) === 1 && $full['byDay'][0]['date'] === WR_DAY
        && near($full['byDay'][0]['loaded'], 120000) && near($full['byDay'][0]['consumed'], 60900), json_encode($full['byDay']), $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (e) saldo vigente ===\n";
    $now = TenantClock::now($companyId);
    $expected = 0.0;
    // GetRows() primero: iterar el recordset mientras balance() consulta en
    // la misma conexión corta la iteración.
    $pairs = $db->Execute('SELECT DISTINCT contactid, pocketid FROM wallet_movement WHERE companyid = ?', [$companyId])->GetRows();
    foreach ($pairs as $pair) {
        $expected += $wallet->balance($companyId, (string) $pair['contactid'], (string) $pair['pocketid']);
    }
    $sNow = $report->summary($from, $now, $rocA, $companyId);
    check('(e1) saldo vigente = suma de los saldos de todo el comercio (sin alcance de sucursal)', near($sNow['liability'], $expected),
        "reporte={$sNow['liability']} esperado=$expected", $failures, $checks);
    check('(e2) el bolsillo del arnés debe 120.000 − 60.900 = 59.100',
        near($wallet->balance($companyId, WR_T1, $pid) + $wallet->balance($companyId, WR_T2, $pid), 59100), 'saldos', $failures, $checks);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (f) endpoint: alcance de sucursal y módulo ===\n";
    $session = fn (string $uid) => authSessionCreate('panel', [
        'companyId' => $companyId, 'userId' => $uid, 'outletId' => $outletId, 'roleId' => $ownerRole,
        'expiresAt' => date('Y-m-d H:i:s', time() + 3600), 'userAgent' => WR_MARCA,
    ]);
    $tokA = $session(WR_USA);
    $tokG = $session(WR_USG);
    $q = 'from=' . WR_DAY . '&to=' . WR_DAY . '&dataset=full';

    // Los dos piden "Todas" (el consolidado): para el acotado son SUS sucursales.
    $r = wcall($q, $tokA, 'all');
    $ids = array_column($r['data']['differences']['rows'] ?? [], 'transactionId');
    check('(f1) usuario acotado a A pidiendo todas: 200 y NO ve el consumo de B', $r['status'] === 200 && !in_array($c4, $ids, true) && in_array($c2, $ids, true),
        $r['body'], $failures, $checks);
    check('(f2) y sus KPIs son los de A', near((float) ($r['data']['summary']['consumed'] ?? -1), 55900), json_encode($r['data']['summary'] ?? null), $failures, $checks);
    $r = wcall($q, $tokG, 'all');
    $ids = array_column($r['data']['differences']['rows'] ?? [], 'transactionId');
    check('(f3) usuario global pidiendo todas: ve el consumo de B', $r['status'] === 200 && in_array($c4, $ids, true), $r['body'], $failures, $checks);

    $modules->toggle($companyId, 'wallet', false);
    $r = wcall($q, $tokG, 'all');
    check('(f4) módulo apagado → 403', $r['status'] === 403, $r['body'], $failures, $checks);
    $modules->toggle($companyId, 'wallet', true);

    // ═════════════════════════════════════════════════════════════════════════
    echo "\n=== (g) la caja no puede disfrazar un producto de add-on ===\n";
    // Va al final a propósito: suma un consumo más y los KPIs de arriba ya se
    // midieron. El `type` de la línea viene del payload; si decidiera qué es
    // una hija, la lista de este producto sería su propio precio bajado.
    $c7 = $consume($saleA, WR_T1, WR_OPX, [array_merge($item(5000), ['type' => 'addon'])], '11:00:00');
    $r = $lt($c7);
    check('(g1) línea con type=addon del payload: la lista sigue siendo la del catálogo (11.000)',
        near((float) $r['l'], 11000), json_encode($r), $failures, $checks);
} finally {
    ncmExecute('DELETE FROM auth_session WHERE useragent = ?', [WR_MARCA]);
    $modules->toggle($companyId, 'wallet', false);
}

harnessFinish($failures, $checks);
