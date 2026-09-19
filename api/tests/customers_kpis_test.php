<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés de los KPIs de clientes (`CustomersService::kpis()`) y de la card
 * "Clientes" del Inicio (`DashboardService` widget `customers`), que desde el
 * 2026-09-19 delega ahí.
 *
 * El bug que fija: el dashboard definía "nuevo" como contacto DADO DE ALTA en
 * el período (`contact.contactDate`). En un tenant migrado todos los contactos
 * se dieron de alta el día de la migración, así que la card mostraba
 * Total = Nuevos, Recurrentes 0, Retorno 0%, Churn 0%. La definición correcta
 * —la del reporte de clientes— es por VENTAS: nuevo = primera venta histórica
 * dentro del período.
 *
 * Fixture (empresa A con sucursales A1 y A2; TODOS los contactos dados de alta
 * el 10/06, dentro del período 01/06 → 30/06; sesión en UTC):
 *   M1  compró el 10/03 y el 05/06 (A1)           → RECURRENTE
 *   M2  compró el 15/05, el 12/06 y el 20/06 (A1) → RECURRENTE, retenido, 2+ compras
 *   N1  primera compra el 08/06 (A1)              → NUEVO
 *   L1  compró solo el 20/05 (A1)                 → activo del período anterior, perdido
 *   V   venta ANULADA el 01/02 (A1) y venta el 25/06 en A2 → NUEVO (la anulada no
 *       lo vuelve recurrente) y fuera del alcance [A1]
 *   Una venta de OTRA empresa el 10/06: aislamiento de tenant.
 *
 * Uso: `bash api/tests/run_customers_kpis_test.sh` (levanta Postgres descartable).
 */

$companyId = '0f5e7a10-0000-4000-8000-0000000002d1';
$companyB  = '0f5e7a10-0000-4000-8000-0000000002d2';
$outletA1  = '0f5e7a10-0000-4000-8000-0000000002d3';
$outletA2  = '0f5e7a10-0000-4000-8000-0000000002d5';
$outletB   = '0f5e7a10-0000-4000-8000-0000000002d4';

const CK_USER = '0f5e7a10-0000-4000-8000-0000000002e1';
const CK_M1   = '0f5e7a10-0000-4000-8000-0000000002c1';
const CK_M2   = '0f5e7a10-0000-4000-8000-0000000002c2';
const CK_N1   = '0f5e7a10-0000-4000-8000-0000000002c3';
const CK_L1   = '0f5e7a10-0000-4000-8000-0000000002c4';
const CK_V    = '0f5e7a10-0000-4000-8000-0000000002c5';
const CK_CB   = '0f5e7a10-0000-4000-8000-0000000002c6';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA1);
define('USER_ID',    CK_USER);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\CustomersService;
use Punto\Api\Reports\DashboardService;

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

/** Igualdad de tasas con tolerancia (float) o ambas null. */
function sameRate($a, $b): bool
{
    if ($a === null || $b === null) return $a === $b;
    return abs((float) $a - (float) $b) < 0.001;
}

$cleanup = static function () use ($companyId, $companyB): void {
    global $db;
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
        [sprintf('0f5e7a10-0000-4000-8000-%012d', 800000 + $seq), $date, CK_USER, $outletId, $companyId, $customerId, $voidedAt]
    );
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
             VALUES (?::uuid, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Customers KPIs Test\"}'::jsonb)",
            [$cid]
        );
    }
    foreach ([[$outletA1, 'A1', $companyId], [$outletA2, 'A2', $companyId], [$outletB, 'B', $companyB]] as [$oid, $name, $cid]) {
        $db->Execute('INSERT INTO outlet (outletid, outletname, outletstatus, companyid) VALUES (?::uuid, ?, 1, ?::uuid)',
            [$oid, $name, $cid]);
    }

    // Todos dados de alta DENTRO del período: el caso del tenant migrado.
    foreach ([
        [CK_USER, 'Cajero',    $companyId, $outletA1, 0],
        [CK_M1,   'Migrado 1', $companyId, $outletA1, 1],
        [CK_M2,   'Migrado 2', $companyId, $outletA1, 1],
        [CK_N1,   'Nuevo 1',   $companyId, $outletA1, 1],
        [CK_L1,   'Perdido 1', $companyId, $outletA1, 1],
        [CK_V,    'Sucursal 2', $companyId, $outletA2, 1],
        [CK_CB,   'Cliente B', $companyB,  $outletB,  1],
    ] as [$id, $name, $cid, $oid, $type]) {
        $db->Execute(
            "INSERT INTO contact (contactid, contactname, companyid, outletid, type, contactstatus, contactdate)
             VALUES (?::uuid, ?, ?::uuid, ?::uuid, ?, 1, '2026-06-10 12:00:00+00')",
            [$id, $name, $cid, $oid, $type]
        );
    }

    $sale($companyId, $outletA1, CK_M1, '2026-03-10 12:00:00+00');
    $sale($companyId, $outletA1, CK_M1, '2026-06-05 12:00:00+00');
    $sale($companyId, $outletA1, CK_M2, '2026-05-15 12:00:00+00');
    $sale($companyId, $outletA1, CK_M2, '2026-06-12 12:00:00+00');
    $sale($companyId, $outletA1, CK_M2, '2026-06-20 12:00:00+00');
    $sale($companyId, $outletA1, CK_N1, '2026-06-08 12:00:00+00');
    $sale($companyId, $outletA1, CK_L1, '2026-05-20 12:00:00+00');
    $sale($companyId, $outletA1, CK_V,  '2026-02-01 12:00:00+00', '2026-02-01 13:00:00+00'); // anulada
    $sale($companyId, $outletA2, CK_V,  '2026-06-25 12:00:00+00');
    $sale($companyB,  $outletB,  CK_CB, '2026-06-10 12:00:00+00');

    $svc = new CustomersService();

    /* ═══ Todo el tenant ═══════════════════════════════════════════════════ */
    $k = $svc->kpis($from, $to, $companyId, []);
    $t = $k['totales'];
    check('contactos dados de alta en el período pero con compras previas ⇒ recurrentes > 0',
        (int) $t['recurrentes'] === 2, json_encode($t), $failures, $checks);
    check('primera compra en el período ⇒ nuevo (N1 y V; la anulada de V no lo hace recurrente)',
        (int) $t['nuevos'] === 2, json_encode($t), $failures, $checks);
    check('activos = 4 (L1 no compró en el período; la otra empresa no cuenta)',
        (int) $t['activos'] === 4, json_encode($t), $failures, $checks);
    check('retorno = 25% (solo M2 compró 2+ veces)',
        sameRate($k['tasas']['retorno'], 25.0), json_encode($k['tasas']), $failures, $checks);
    check('retención 50% / pérdida 50% (del anterior: M2 volvió, L1 no)',
        sameRate($k['tasas']['retencion'], 50.0) && sameRate($k['tasas']['perdida'], 50.0), json_encode($k['tasas']), $failures, $checks);
    check('crecimiento 100% (2 activos antes, 4 ahora)',
        sameRate($k['tasas']['crecimiento'], 100.0), json_encode($k['tasas']), $failures, $checks);
    check('kpis() no trae la serie', !isset($k['serie']) && !isset($k['granularity']), implode(',', array_keys($k)), $failures, $checks);

    /* ═══ Alcance por sucursal ═════════════════════════════════════════════ */
    $a1 = $svc->kpis($from, $to, $companyId, [$outletA1]);
    check('alcance [A1]: V (compró en A2) queda afuera — 3 activos, 1 nuevo',
        (int) $a1['totales']['activos'] === 3 && (int) $a1['totales']['nuevos'] === 1, json_encode($a1['totales']), $failures, $checks);
    $both = $svc->kpis($from, $to, $companyId, [$outletA1, $outletA2]);
    check('alcance [A1, A2] = todo el tenant',
        $both['totales'] == $k['totales'] && $both['tasas'] == $k['tasas'], json_encode($both['totales']), $failures, $checks);

    /* ═══ Sin período anterior ═════════════════════════════════════════════ */
    $mar = $svc->kpis('2026-03-01 00:00:00', '2026-03-31 23:59:59', $companyId, []);
    check('sin activos en el período anterior ⇒ retención/pérdida/crecimiento null, nunca 0',
        $mar['tasas']['retencion'] === null && $mar['tasas']['perdida'] === null && $mar['tasas']['crecimiento'] === null,
        json_encode($mar['tasas']), $failures, $checks);
    check('marzo: M1 es nuevo (primera compra 10/03)',
        (int) $mar['totales']['activos'] === 1 && (int) $mar['totales']['nuevos'] === 1, json_encode($mar['totales']), $failures, $checks);

    /* ═══ Dashboard == reporte de clientes ════════════════════════════════ */
    $dash = new DashboardService();
    foreach (['todo el tenant' => [], 'A1' => [$outletA1]] as $label => $scope) {
        $w = $dash->widget('customers', ['from' => $from, 'to' => $to], '', $companyId, $scope, CK_USER);
        $r = $svc->dashboard($from, $to, $companyId, $scope);
        unset($r['serie'], $r['granularity']);
        check("widget customers == reporte de clientes ($label)", $w == $r,
            json_encode(['widget' => $w['totales'] ?? null, 'reporte' => $r['totales']]), $failures, $checks);
    }

    /* ═══ customersSeries (agente IA): misma definición de "nuevo" ═════════ */
    $ser = $dash->widget('customersSeries', ['from' => '2026-01-01 00:00:00', 'to' => $to], '', $companyId, [], CK_USER);
    $byMonth = [];
    foreach ($ser['rows'] as $row) { $byMonth[$row['bucket']] = $row['new']; }
    check('customersSeries cuenta por primera venta: mar=1 (M1), may=2 (M2, L1), jun=2 (N1, V)',
        $byMonth === ['2026-03' => 1, '2026-05' => 2, '2026-06' => 2], json_encode($byMonth), $failures, $checks);
    $semestre = $svc->kpis('2026-01-01 00:00:00', $to, $companyId, []);
    check('la suma de la serie da los nuevos del mismo rango',
        array_sum($byMonth) === (int) $semestre['totales']['nuevos'], json_encode([array_sum($byMonth), $semestre['totales']]), $failures, $checks);
} catch (\Throwable $e) {
    check('kpis() corre sin error', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), $failures, $checks);
} finally {
    $cleanup();
}

harnessFinish($failures, $checks);
