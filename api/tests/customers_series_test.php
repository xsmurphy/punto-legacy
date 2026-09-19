<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de la serie "Nuevos vs recurrentes" del Análisis de clientes
 * (`CustomersService::dashboard()`) con la granularidad por largo del rango
 * (`Support\TimeBuckets`).
 *
 * La regla que se fija acá: un cliente es NUEVO en el período (día, semana o
 * mes) de su PRIMERA compra histórica y RECURRENTE en cualquier otro en el que
 * compre, y cuenta UNA sola vez por período aunque compre varias veces.
 *
 * Fixture (empresa A, sesión en UTC):
 *   C1  primera compra 2025-12-10 (fuera de todo rango) y compra el 03/03 y
 *       el 04/03 — la MISMA semana ISO (lunes 02/03).
 *   C2  primera compra 03/03 y vuelve el 17/03.
 *   C3  primera compra 18/03.
 *   Una venta ANULADA de C3 el 05/03: si contara, C3 sería nuevo en la
 *   semana del 02/03 en vez de la del 16/03.
 *   Una venta de OTRA empresa el 03/03: aislamiento de tenant.
 *
 * Uso: `bash api/tests/run_customers_series_test.sh` (levanta Postgres descartable).
 */

$companyId = '0f5e7a10-0000-4000-8000-0000000001d1';
$companyB  = '0f5e7a10-0000-4000-8000-0000000001d2';
$outletA   = '0f5e7a10-0000-4000-8000-0000000001d3';
$outletB   = '0f5e7a10-0000-4000-8000-0000000001d4';

const CS_USER = '0f5e7a10-0000-4000-8000-0000000001e1';
const CS_C1   = '0f5e7a10-0000-4000-8000-0000000001c1';
const CS_C2   = '0f5e7a10-0000-4000-8000-0000000001c2';
const CS_C3   = '0f5e7a10-0000-4000-8000-0000000001c3';
const CS_CB   = '0f5e7a10-0000-4000-8000-0000000001c4';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA);
define('USER_ID',    CS_USER);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\CustomersService;

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

/** El punto de la serie cuyo bucket empieza en `$bucket`, o null. */
function point(array $serie, string $bucket): ?array
{
    foreach ($serie as $p) {
        if (($p['bucket'] ?? null) === $bucket) return $p;
    }
    return null;
}

/** "nuevos/recurrentes" de un punto, para comparar y mostrar en una línea. */
function nr(?array $p): string
{
    return $p === null ? 'sin punto' : ((int) $p['nuevos']) . '/' . ((int) $p['recurrentes']);
}

$cleanup = static function () use ($companyId, $companyB): void {
    global $db;
    // Las ventas de las DOS empresas primero: la de B apunta al cajero de A.
    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute('DELETE FROM transaction WHERE companyid = ?::uuid', [$cid]);
    }
    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute('DELETE FROM contact     WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM outlet      WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM company     WHERE companyid = ?::uuid', [$cid]);
    }
};

$seq  = 0;
$sale = static function (string $companyId, string $outletId, string $customerId, string $date, ?string $voidedAt = null) use (&$seq): void {
    global $db;
    $seq++;
    $db->Execute(
        "INSERT INTO transaction (transactionid, transactiondate, transactiontotal, transactiontype,
                                  userid, outletid, companyid, customerid, voidedat)
         VALUES (?::uuid, ?::timestamptz, 1000, 0, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::timestamptz)",
        [sprintf('0f5e7a10-0000-4000-8000-%012d', 900000 + $seq), $date, CS_USER, $outletId, $companyId, $customerId, $voidedAt]
    );
};

try {
    $cleanup();
    // Sesión de Postgres Y proceso PHP en la misma zona, como los deja
    // `TenantClock::apply()` en un request real: el corte en SQL y el de
    // `keyFor()` tienen que hablar el mismo huso.
    $db->Execute("SET TIME ZONE 'UTC'");
    date_default_timezone_set('UTC');

    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute(
            "INSERT INTO company (companyid, status, plan, balance, isparent, config)
             VALUES (?::uuid, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Customers Series Test\"}'::jsonb)",
            [$cid]
        );
    }
    $db->Execute('INSERT INTO outlet (outletid, outletname, outletstatus, companyid) VALUES (?::uuid, ?, 1, ?::uuid)',
        [$outletA, 'Clientes A', $companyId]);
    $db->Execute('INSERT INTO outlet (outletid, outletname, outletstatus, companyid) VALUES (?::uuid, ?, 1, ?::uuid)',
        [$outletB, 'Clientes B', $companyB]);

    foreach ([
        [CS_USER, 'Cajero',    $companyId, $outletA, 0],
        [CS_C1,   'Cliente 1', $companyId, $outletA, 1],
        [CS_C2,   'Cliente 2', $companyId, $outletA, 1],
        [CS_C3,   'Cliente 3', $companyId, $outletA, 1],
        [CS_CB,   'Cliente B', $companyB,  $outletB, 1],
    ] as [$id, $name, $cid, $oid, $type]) {
        $db->Execute(
            'INSERT INTO contact (contactid, contactname, companyid, outletid, type, contactstatus)
             VALUES (?::uuid, ?, ?::uuid, ?::uuid, ?, 1)',
            [$id, $name, $cid, $oid, $type]
        );
    }

    $sale($companyId, $outletA, CS_C1, '2025-12-10 12:00:00+00');
    $sale($companyId, $outletA, CS_C1, '2026-03-03 12:00:00+00');
    $sale($companyId, $outletA, CS_C1, '2026-03-04 12:00:00+00');
    $sale($companyId, $outletA, CS_C2, '2026-03-03 15:00:00+00');
    $sale($companyId, $outletA, CS_C2, '2026-03-17 15:00:00+00');
    $sale($companyId, $outletA, CS_C3, '2026-03-18 09:00:00+00');
    $sale($companyId, $outletA, CS_C3, '2026-03-05 09:00:00+00', '2026-03-05 10:00:00+00'); // anulada
    $sale($companyB,  $outletB, CS_CB, '2026-03-03 12:00:00+00');

    $svc = new CustomersService();

    /* ═══ Semanal: 01/03 → 15/04 (46 días) ═════════════════════════════════ */
    $w = $svc->dashboard('2026-03-01 00:00:00', '2026-04-15 23:59:59', $companyId, [$outletA]);
    $s = $w['serie'];
    check('46 días grafican por SEMANA', ($w['granularity'] ?? null) === 'week', var_export($w['granularity'] ?? null, true), $failures, $checks);
    check('la primera semana arranca el lunes 23/02 y es parcial',
        ($s[0]['bucket'] ?? null) === '2026-02-23' && ($s[0]['partial'] ?? null) === true, json_encode($s[0] ?? null), $failures, $checks);
    check('la última semana (13/04) es parcial',
        (end($s)['bucket'] ?? null) === '2026-04-13' && (end($s)['partial'] ?? null) === true, json_encode(end($s)), $failures, $checks);
    check('semana del 02/03: C2 nuevo, C1 recurrente UNA vez aunque compró dos días',
        nr(point($s, '2026-03-02')) === '1/1', nr(point($s, '2026-03-02')), $failures, $checks);
    check('semana del 09/03: vacía pero presente en el eje (la anulada no cuenta)',
        nr(point($s, '2026-03-09')) === '0/0', nr(point($s, '2026-03-09')), $failures, $checks);
    check('semana del 16/03: C3 nuevo, C2 recurrente (volvió)',
        nr(point($s, '2026-03-16')) === '1/1', nr(point($s, '2026-03-16')), $failures, $checks);
    check('totales del período: 3 activos, 2 nuevos',
        (int) $w['totales']['activos'] === 3 && (int) $w['totales']['nuevos'] === 2, json_encode($w['totales']), $failures, $checks);

    /* ═══ Mensual: 01/01 → 31/05 (151 días) ════════════════════════════════ */
    $m = $svc->dashboard('2026-01-01 00:00:00', '2026-05-31 23:59:59', $companyId, [$outletA]);
    check('151 días grafican por MES', ($m['granularity'] ?? null) === 'month', var_export($m['granularity'] ?? null, true), $failures, $checks);
    check('marzo: C2 y C3 nuevos, C1 recurrente; C2 cuenta una vez aunque compró dos veces',
        nr(point($m['serie'], '2026-03-01')) === '2/1', nr(point($m['serie'], '2026-03-01')), $failures, $checks);
    check('cinco meses en el eje, ninguno parcial',
        count($m['serie']) === 5 && array_filter($m['serie'], static fn($p) => $p['partial']) === [], (string) count($m['serie']), $failures, $checks);

    /* ═══ Diario: marzo (31 días) ══════════════════════════════════════════ */
    $d = $svc->dashboard('2026-03-01 00:00:00', '2026-03-31 23:59:59', $companyId, [$outletA]);
    check('un mes grafica por DÍA, con los 31 días', ($d['granularity'] ?? null) === 'day' && count($d['serie']) === 31,
        var_export($d['granularity'] ?? null, true) . ' / ' . count($d['serie']), $failures, $checks);
    check('03/03: C2 nuevo, C1 recurrente', nr(point($d['serie'], '2026-03-03')) === '1/1', nr(point($d['serie'], '2026-03-03')), $failures, $checks);
    check('04/03: C1 recurrente', nr(point($d['serie'], '2026-03-04')) === '0/1', nr(point($d['serie'], '2026-03-04')), $failures, $checks);
    check('17/03: C2 recurrente', nr(point($d['serie'], '2026-03-17')) === '0/1', nr(point($d['serie'], '2026-03-17')), $failures, $checks);
    check('18/03: C3 nuevo', nr(point($d['serie'], '2026-03-18')) === '1/0', nr(point($d['serie'], '2026-03-18')), $failures, $checks);

    /* ═══ Serie de ventas del dashboard (SalesService::series) ═════════════ */
    // El gráfico "Margen, Ingresos y Egresos" sale de acá: mismo fixture,
    // mismo grano. La semana del 02/03 junta tres ventas de 1.000.
    $sales = new \Punto\Api\Reports\SalesService();
    $ser   = $sales->series('2026-03-01 00:00:00', '2026-04-15 23:59:59', \Punto\Api\Reports\Roc::build($companyId, $outletA), false);
    $tot   = [];
    foreach ($ser['sales'] as $r) { $tot[(string) $r['bucket']] = (float) $r['total']; }
    check('ventas: 46 días → semanal, con el calendario completo',
        $ser['granularity'] === 'week' && count($ser['buckets']) === 8 && $ser['buckets'][0]['partial'] === true,
        json_encode([$ser['granularity'], count($ser['buckets'])]), $failures, $checks);
    check('ventas: la semana del 02/03 suma 3.000 (la anulada no)', ($tot['2026-03-02'] ?? null) === 3000.0, json_encode($tot), $failures, $checks);
    check('ventas: la semana del 16/03 suma 2.000', ($tot['2026-03-16'] ?? null) === 2000.0, json_encode($tot), $failures, $checks);
    $hr = $sales->series('2026-03-03 00:00:00', '2026-03-03 23:59:59', \Punto\Api\Reports\Roc::build($companyId, $outletA), true);
    check('ventas: un solo día sigue por hora (24 buckets)', $hr['granularity'] === 'hour' && count($hr['buckets']) === 24,
        json_encode([$hr['granularity'], count($hr['buckets'])]), $failures, $checks);
} catch (\Throwable $e) {
    check('dashboard() corre sin error', false, get_class($e) . ': ' . $e->getMessage(), $failures, $checks);
} finally {
    $cleanup();
}

harnessFinish($failures, $checks);
