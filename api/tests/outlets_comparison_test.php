<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del reporte de Sucursales (`OutletsComparisonService`,
 * `GET /v1/reports/outlets`) y del refactor que lo sostiene (`PeriodStats`,
 * la fórmula única de ventas/ganancia/margen del dashboard).
 *
 * Lo que fija:
 *   1. La suma de las sucursales = el KPI del dashboard (`incomeOutcomeStats`)
 *      para el mismo rango: ventas, ganancia, egresos y cantidad; y la fila
 *      total ES ese KPI (margen y ticket incluidos).
 *   2. Un alcance restringido no deja ver sucursales ajenas: ni en las filas
 *      ni en el total, ni en la serie ni en la operación. Otra empresa, nunca.
 *   3. `previous` (base del delta) es null cuando no hubo movimiento en el
 *      período anterior — por sucursal y para el total.
 *   4. La suma de los puntos de la serie de una sucursal = sus ventas.
 *   5. Clientes activos: el total es el distinct (= `CustomersService::kpis()`),
 *      no la suma de las sucursales.
 *
 * Fixture (sesión en UTC; período 01/06 → 30/06; el anterior cae en mayo):
 *   Empresa A: A1 y A2 activas, A3 activa sin movimiento, A4 INACTIVA sin nada.
 *     A1  venta 1000 desc 100 (C1) 05/06 · venta 500 (C2) 20/06 · venta 700
 *         ANULADA 10/06 · egreso 300 12/06 · venta 400 (C1) 20/05 (anterior)
 *     A2  venta 2000 (C1) 15/06 · egreso 2500 16/06 (ganancia negativa)
 *   Empresa B: una venta de 9999 el 10/06 — aislamiento de tenant.
 *
 * Uso: `bash api/tests/run_outlets_comparison_test.sh` (levanta Postgres descartable).
 */

$companyId = '0f5e7a10-0000-4000-8000-0000000003d1';
$companyB  = '0f5e7a10-0000-4000-8000-0000000003d2';
$outletA1  = '0f5e7a10-0000-4000-8000-0000000003a1';
$outletA2  = '0f5e7a10-0000-4000-8000-0000000003a2';
$outletA3  = '0f5e7a10-0000-4000-8000-0000000003a3';
$outletA4  = '0f5e7a10-0000-4000-8000-0000000003a4';
$outletB   = '0f5e7a10-0000-4000-8000-0000000003b1';

const OC_USER  = '0f5e7a10-0000-4000-8000-0000000003e1';
const OC_USERB = '0f5e7a10-0000-4000-8000-0000000003e2';
const OC_C1    = '0f5e7a10-0000-4000-8000-0000000003c1';
const OC_C2    = '0f5e7a10-0000-4000-8000-0000000003c2';
const OC_ITEM  = '0f5e7a10-0000-4000-8000-0000000003f1';
const OC_ITEMB = '0f5e7a10-0000-4000-8000-0000000003f2';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA1);
define('USER_ID',    OC_USER);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\CustomersService;
use Punto\Api\Reports\DashboardService;
use Punto\Api\Reports\OutletsComparisonService;
use Punto\Api\Reports\Roc;

/** @var \Punto\Api\Database\Query $db */
global $db;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function near(float $a, float $b): bool
{
    return abs($a - $b) < 0.005;
}

$cleanup = static function () use ($companyId, $companyB): void {
    global $db;
    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute('DELETE FROM itemsold    WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM transaction WHERE companyid = ?::uuid', [$cid]);
    }
    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute('DELETE FROM item    WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM contact WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM outlet  WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM company WHERE companyid = ?::uuid', [$cid]);
    }
};

$seq = 0;
/** Inserta una transacción y (si es venta) una línea del artículo. */
$tx = static function (
    string $companyId, string $outletId, string $userId, int $type, float $total, string $date,
    ?string $customerId = null, float $discount = 0.0, ?string $voidedAt = null, ?string $itemId = null
) use (&$seq): void {
    global $db;
    $seq++;
    $tid = sprintf('0f5e7a10-0000-4000-8000-%012d', 900000 + $seq);
    $db->Execute(
        "INSERT INTO transaction (transactionid, transactiondate, transactiontotal, transactiondiscount,
                                  transactiontype, transactionstatus, transactionunitssold,
                                  userid, outletid, companyid, customerid, voidedat)
         VALUES (?::uuid, ?::timestamptz, ?, ?, ?, 1, 1, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::timestamptz)",
        [$tid, $date, $total, $discount, $type, $userId, $outletId, $companyId, $customerId, $voidedAt]
    );
    if ($itemId !== null) {
        $db->Execute(
            "INSERT INTO itemsold (itemsoldtotal, itemsolddate, itemsoldunits, itemid, userid, transactionid, companyid, outletid)
             VALUES (?, ?::timestamptz, 2, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid)",
            [$total, $date, $itemId, $userId, $tid, $companyId, $outletId]
        );
    }
};

$from = '2026-06-01 00:00:00';
$to   = '2026-06-30 23:59:59';

try {
    $cleanup();
    $db->Execute("SET TIME ZONE 'UTC'");
    date_default_timezone_set('UTC');

    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute(
            "INSERT INTO company (companyid, status, plan, balance, isparent, config)
             VALUES (?::uuid, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Outlets Comparison Test\"}'::jsonb)",
            [$cid]
        );
    }
    foreach ([
        [$outletA1, 'A1 Centro', $companyId, 1],
        [$outletA2, 'A2 Norte',  $companyId, 1],
        [$outletA3, 'A3 Sur',    $companyId, 1],
        [$outletA4, 'A4 Cerrada', $companyId, 0],
        [$outletB,  'B',         $companyB,  1],
    ] as [$oid, $name, $cid, $status]) {
        $db->Execute('INSERT INTO outlet (outletid, outletname, outletstatus, companyid) VALUES (?::uuid, ?, ?, ?::uuid)',
            [$oid, $name, $status, $cid]);
    }
    foreach ([
        [OC_USER,  'Cajero A',  $companyId, $outletA1, 0],
        [OC_USERB, 'Cajero B',  $companyB,  $outletB,  0],
        [OC_C1,    'Cliente 1', $companyId, $outletA1, 1],
        [OC_C2,    'Cliente 2', $companyId, $outletA1, 1],
    ] as [$id, $name, $cid, $oid, $type]) {
        $db->Execute(
            "INSERT INTO contact (contactid, contactname, companyid, outletid, type, contactstatus, contactdate)
             VALUES (?::uuid, ?, ?::uuid, ?::uuid, ?, 1, '2026-01-01 12:00:00+00')",
            [$id, $name, $cid, $oid, $type]
        );
    }
    foreach ([[OC_ITEM, 'Café', $companyId], [OC_ITEMB, 'Ajeno', $companyB]] as [$iid, $name, $cid]) {
        $db->Execute('INSERT INTO item (itemid, itemname, companyid) VALUES (?::uuid, ?, ?::uuid)', [$iid, $name, $cid]);
    }

    // A1
    $tx($companyId, $outletA1, OC_USER, 0, 1000, '2026-06-05 10:00:00+00', OC_C1, 100, null, OC_ITEM);
    $tx($companyId, $outletA1, OC_USER, 0,  500, '2026-06-20 18:00:00+00', OC_C2, 0, null, OC_ITEM);
    $tx($companyId, $outletA1, OC_USER, 0,  700, '2026-06-10 11:00:00+00', OC_C2, 0, '2026-06-10 12:00:00+00', OC_ITEM);
    $tx($companyId, $outletA1, OC_USER, 1,  300, '2026-06-12 09:00:00+00');
    $tx($companyId, $outletA1, OC_USER, 0,  400, '2026-05-20 10:00:00+00', OC_C1);
    // A2
    $tx($companyId, $outletA2, OC_USER, 0, 2000, '2026-06-15 13:00:00+00', OC_C1, 0, null, OC_ITEM);
    $tx($companyId, $outletA2, OC_USER, 4, 2500, '2026-06-16 09:00:00+00');
    // Empresa B
    $tx($companyB, $outletB, OC_USERB, 0, 9999, '2026-06-10 10:00:00+00', null, 0, null, OC_ITEMB);

    $svc  = new OutletsComparisonService();
    $dash = new DashboardService();

    /* ═══ 1. Suma de sucursales = dashboard ═══════════════════════════════ */
    $sum  = $svc->summary($from, $to, $companyId, []);
    $kpi  = $dash->widget('incomeOutcomeStats', ['from' => $from, 'to' => $to], Roc::scoped($companyId, []), $companyId, [], OC_USER);
    $rows = [];
    foreach ($sum['rows'] as $r) { $rows[$r['outletId']] = $r; }

    $sumOf = static fn (string $k): float => (float) array_sum(array_column($sum['rows'], $k));
    check('suma de ventas de las sucursales = Ingresos del dashboard (3400)',
        near($sumOf('total'), (float) $kpi['total']) && near((float) $kpi['total'], 3400.0),
        json_encode(['filas' => $sumOf('total'), 'dashboard' => $kpi['total']]), $failures, $checks);
    check('suma de ganancia = ganancia del dashboard (600)',
        near($sumOf('revenue'), (float) $kpi['revenue']) && near((float) $kpi['revenue'], 600.0),
        json_encode(['filas' => $sumOf('revenue'), 'dashboard' => $kpi['revenue']]), $failures, $checks);
    check('suma de egresos y de cantidad = dashboard',
        near($sumOf('expenses'), (float) $kpi['expenses']) && (int) $sumOf('count') === (int) $kpi['count'],
        json_encode(['filas' => [$sumOf('expenses'), $sumOf('count')], 'dashboard' => [$kpi['expenses'], $kpi['count']]]), $failures, $checks);
    $t = $sum['total'];
    check('fila total = KPI del dashboard (ventas, ganancia, margen, ticket, cantidad)',
        near((float) $t['total'], (float) $kpi['total']) && near((float) $t['revenue'], (float) $kpi['revenue'])
        && (float) $t['margin'] === (float) $kpi['margin'] && near((float) $t['customerAverage'], (float) $kpi['customerAverage'])
        && (int) $t['count'] === (int) $kpi['count'],
        json_encode(['total' => $t, 'dashboard' => $kpi]), $failures, $checks);
    check('la venta anulada no suma (A1 = 900 + 500)',
        near((float) ($rows[$outletA1]['total'] ?? -1), 1400.0) && (int) $rows[$outletA1]['count'] === 2,
        json_encode($rows[$outletA1] ?? null), $failures, $checks);
    check('A2: ganancia negativa (-500), margen con el piso del dashboard (0)',
        near((float) $rows[$outletA2]['revenue'], -500.0) && (float) $rows[$outletA2]['margin'] === 0.0,
        json_encode($rows[$outletA2]), $failures, $checks);
    check('filas = activas del alcance (A3 sin movimiento incluida, A4 inactiva sin movimiento no, B nunca)',
        isset($rows[$outletA3]) && !isset($rows[$outletA4]) && !isset($rows[$outletB])
        && count($rows) === 3,
        implode(',', array_keys($rows)), $failures, $checks);
    check('orden por ventas desc (A2, A1, A3)',
        array_column($sum['rows'], 'outletId') === [$outletA2, $outletA1, $outletA3],
        implode(',', array_column($sum['rows'], 'name')), $failures, $checks);

    /* ═══ 3. Delta: base del período anterior ═════════════════════════════ */
    check('A1 vendió en mayo ⇒ previous con ventas 400',
        is_array($rows[$outletA1]['previous']) && near((float) $rows[$outletA1]['previous']['total'], 400.0),
        json_encode($rows[$outletA1]['previous']), $failures, $checks);
    check('A2 y A3 sin movimiento en mayo ⇒ previous null (sin delta)',
        $rows[$outletA2]['previous'] === null && $rows[$outletA3]['previous'] === null,
        json_encode([$rows[$outletA2]['previous'], $rows[$outletA3]['previous']]), $failures, $checks);
    $mar = $svc->summary('2026-03-01 00:00:00', '2026-03-31 23:59:59', $companyId, []);
    check('período sin anterior ⇒ previous del total null',
        $mar['total']['previous'] === null, json_encode($mar['total']), $failures, $checks);

    /* ═══ 5. Clientes activos ══════════════════════════════════════════════ */
    $kpis = (new CustomersService())->kpis($from, $to, $companyId, []);
    check('clientes activos: A1=2, A2=1, total distinct=2 (= kpis del reporte de clientes)',
        (int) $rows[$outletA1]['activeCustomers'] === 2 && (int) $rows[$outletA2]['activeCustomers'] === 1
        && (int) $t['activeCustomers'] === 2 && (int) $t['activeCustomers'] === (int) $kpis['totales']['activos'],
        json_encode(['A1' => $rows[$outletA1]['activeCustomers'], 'A2' => $rows[$outletA2]['activeCustomers'], 'total' => $t['activeCustomers'], 'kpis' => $kpis['totales']['activos']]),
        $failures, $checks);

    /* ═══ 4. Serie ═════════════════════════════════════════════════════════ */
    $ser = $svc->series($from, $to, $companyId, []);
    $serieOk = $ser['granularity'] === 'day' && count($ser['buckets']) === 30;
    foreach ($ser['series'] as $s) {
        $pts = array_sum(array_column($s['points'], 'total'));
        $serieOk = $serieOk && near((float) $pts, (float) ($rows[$s['outletId']]['total'] ?? -1)) && count($s['points']) === 30;
    }
    check('serie: grano día, 30 puntos por sucursal, suma = ventas de la tabla',
        $serieOk && count($ser['series']) === 3, json_encode(array_map(static fn ($s) => [$s['name'], array_sum(array_column($s['points'], 'total'))], $ser['series'])), $failures, $checks);

    /* ═══ Operación ════════════════════════════════════════════════════════ */
    $ops  = $svc->operations($from, $to, $companyId, []);
    $opsR = [];
    foreach ($ops['rows'] as $r) { $opsR[$r['outletId']] = $r; }
    check('operación A1: horas pico 10h y 18h (la anulada de las 11h no cuenta)',
        array_column($opsR[$outletA1]['hours'] ?? [], 'hour') == [10, 18] || array_column($opsR[$outletA1]['hours'] ?? [], 'hour') == [18, 10],
        json_encode($opsR[$outletA1]['hours'] ?? null), $failures, $checks);
    check('operación A1: top artículo Café con 4 unidades (sin la anulada)',
        ($opsR[$outletA1]['topItems'][0]['name'] ?? '') === 'Café' && near((float) $opsR[$outletA1]['topItems'][0]['units'], 4.0),
        json_encode($opsR[$outletA1]['topItems'] ?? null), $failures, $checks);

    /* ═══ 2. Alcance restringido ═══════════════════════════════════════════ */
    $only = $svc->summary($from, $to, $companyId, [$outletA1]);
    check('alcance [A1]: una sola fila, total = A1 (A2 no se filtra ni al total)',
        array_column($only['rows'], 'outletId') === [$outletA1] && near((float) $only['total']['total'], 1400.0)
        && near((float) $only['total']['expenses'], 300.0) && (int) $only['total']['activeCustomers'] === 2,
        json_encode($only), $failures, $checks);
    $onlySer = $svc->series($from, $to, $companyId, [$outletA1]);
    $onlyOps = $svc->operations($from, $to, $companyId, [$outletA1]);
    check('alcance [A1]: serie y operación tampoco traen A2',
        array_column($onlySer['series'], 'outletId') === [$outletA1] && array_column($onlyOps['rows'], 'outletId') === [$outletA1],
        json_encode([array_column($onlySer['series'], 'name'), array_column($onlyOps['rows'], 'name')]), $failures, $checks);
    $foreign = $svc->summary($from, $to, $companyId, [$outletB]);
    check('alcance con una sucursal de OTRA empresa ⇒ nada (el companyId manda)',
        $foreign['rows'] === [] && near((float) $foreign['total']['total'], 0.0),
        json_encode($foreign), $failures, $checks);
    $bSum = $svc->summary($from, $to, $companyB, []);
    check('empresa B no ve A1/A2 y su total es solo lo suyo',
        array_column($bSum['rows'], 'outletId') === [$outletB] && near((float) $bSum['total']['total'], 9999.0),
        json_encode($bSum['total']), $failures, $checks);

    /* ═══ Regresión del refactor: salesByOutlet del dashboard ══════════════ */
    $sbo = $dash->widget('salesByOutlet', ['from' => $from, 'to' => $to], Roc::scoped($companyId, []), $companyId, [], OC_USER);
    $sboBy = [];
    foreach ($sbo['rows'] as $r) { $sboBy[$r['outletId']] = $r; }
    check('dashboard salesByOutlet sigue igual: A2 2000, A1 1400 (previous 400), sin A3',
        count($sbo['rows']) === 2 && near((float) $sboBy[$outletA2]['total'], 2000.0) && near((float) $sboBy[$outletA1]['total'], 1400.0)
        && near((float) $sboBy[$outletA1]['previous'], 400.0) && $sboBy[$outletA2]['previous'] === null,
        json_encode($sbo), $failures, $checks);
} catch (\Throwable $e) {
    check('el reporte corre sin error', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), $failures, $checks);
} finally {
    $cleanup();
}

harnessFinish($failures, $checks);
