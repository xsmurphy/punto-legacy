<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del reporte de EQUIPO (`UsersService`, `GET /v1/reports/users`).
 *
 * Las vistas `summary` y `commissions` se escribieron en el mismo sprint en que
 * un reporte salió a producción con una columna inexistente y devolvió 500: acá
 * el SQL corre contra Postgres real y cada número se compara contra el valor
 * calculado a mano abajo.
 *
 * ── Los casos que importan ───────────────────────────────────────────────────
 *
 *   T1  venta de Ana (operador), 2 líneas SIN vendedor de línea → las dos se
 *       atribuyen a Ana por el COALESCE. Una de las líneas lleva descuento.
 *   T2  venta de Ana (operador) con la línea asignada a Bruno → es de BRUNO.
 *       Es el lado "vendedor por línea" del COALESCE.
 *   T3  venta de Bruno con DOS vendedores: una línea de Ana y otra sin asignar
 *       (Bruno). La misma transacción es un ticket de cada uno, y UNO SOLO del
 *       comercio: por eso la suma de tickets por vendedor (5) es mayor que el
 *       total global (4), y el ticket promedio global NO se calcula sumando
 *       filas.
 *   T4  ANULADA (`voidedat`): fuera de todo. Es lo que `SaleFilters` garantiza.
 *   T5  `transactionType = 5` (no es venta): fuera de todo.
 *   T6  vendida por un usuario DADO DE BAJA (`contactStatus = 0`): fuera, el
 *       JOIN contra contacto activo la descarta.
 *   T7  fuera del rango de fechas.
 *   T8  de OTRA empresa, mismo día y mismos montos: aislamiento de tenant.
 *   T9  DEVOLUCIÓN (`transactionType = 6`): entra con signo negativo, tanto en
 *       el total como en la comisión. Es el caso que justifica que el reporte
 *       no filtre por `> 0` en ningún lado.
 *
 *   Ema es un usuario ACTIVO sin ninguna venta: tiene que aparecer en 0 en la
 *   vista default (que es su contrato) y NO aparecer en el ranking del
 *   dashboard, que es "quién vendió".
 *
 * Uso: `bash api/tests/run_users_report_test.sh` (levanta Postgres descartable).
 */

$companyId = '0f5e7a10-0000-4000-8000-0000000000d1';
$companyB  = '0f5e7a10-0000-4000-8000-0000000000d2';
$outletA   = '0f5e7a10-0000-4000-8000-0000000000d3';
$outletB   = '0f5e7a10-0000-4000-8000-0000000000d4';

const U_ANA   = '0f5e7a10-0000-4000-8000-0000000000e1';
const U_BRUNO = '0f5e7a10-0000-4000-8000-0000000000e2';
const U_CARLA = '0f5e7a10-0000-4000-8000-0000000000e3'; // dada de baja
const U_EMA   = '0f5e7a10-0000-4000-8000-0000000000e4'; // activa, sin ventas
const U_BETO  = '0f5e7a10-0000-4000-8000-0000000000e5'; // de la empresa B
const ITEM_A  = '0f5e7a10-0000-4000-8000-0000000000f1';
const ITEM_B  = '0f5e7a10-0000-4000-8000-0000000000f2';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA);
define('USER_ID',    U_ANA);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\UsersService;

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

/** Comparación de plata: tolerancia de medio guaraní. */
function near(mixed $a, float $b): bool
{
    return is_numeric($a) && abs(((float) $a) - $b) < 0.5;
}

/** Comparación de porcentaje/promedio: dos decimales. */
function nearPct(mixed $a, float $b): bool
{
    return is_numeric($a) && abs(((float) $a) - $b) < 0.01;
}

function v(mixed $x): string
{
    return var_export($x, true);
}

/** Busca una fila por userId en un array de filas del reporte. */
function byUser(array $rows, string $userId): ?array
{
    foreach ($rows as $r) {
        if (($r['userId'] ?? null) === $userId) return $r;
    }
    return null;
}

// ── Fixtures ────────────────────────────────────────────────────────────────

$seedTx = static function (
    string $txId, string $companyId, string $outletId, string $operatorId,
    string $date, int $type, float $total, ?string $voidedAt,
    ?int $invoiceNo, string $invoicePrefix
): void {
    global $db;
    $db->Execute(
        "INSERT INTO transaction (transactionid, transactiondate, transactiontotal, transactiontype,
                                  userid, outletid, companyid, invoiceno, invoiceprefix, voidedat)
         VALUES (?::uuid, ?::timestamptz, ?, ?, ?::uuid, ?::uuid, ?::uuid, ?, ?, ?::timestamptz)",
        [$txId, $date, $total, $type, $operatorId, $outletId, $companyId,
         $invoiceNo, $invoicePrefix, $voidedAt]
    );
};

$seedLine = static function (
    string $txId, string $companyId, string $outletId, ?string $lineSeller,
    string $date, float $total, float $comission, float $discount, float $units,
    string $itemId = ITEM_A
): void {
    global $db;
    $db->Execute(
        "INSERT INTO itemsold (itemsoldtotal, itemsolddate, itemsoldunits, itemsolddiscount,
                               itemsoldcomission, itemid, userid, transactionid, companyid, outletid)
         VALUES (?, ?::timestamptz, ?, ?, ?, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid)",
        [$total, $date, $units, $discount, $comission, $itemId, $lineSeller, $txId, $companyId, $outletId]
    );
};

$cleanup = static function () use ($companyId, $companyB): void {
    global $db;
    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute('DELETE FROM itemsold    WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM transaction WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM item        WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM contact     WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM outlet      WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM company     WHERE companyid = ?::uuid', [$cid]);
    }
};

$svc  = new UsersService();
$from = '2026-03-01 00:00:00';
$to   = '2026-03-31 23:59:59';

try {
    $cleanup();
    // Horas escritas en UTC explícito y sesión en UTC: el corte por día de la
    // serie diaria (`::date`, que lee la zona de la sesión) cae donde dice el
    // fixture y no un día antes.
    $db->Execute("SET TIME ZONE 'UTC'");

    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute(
            "INSERT INTO company (companyid, status, plan, balance, isparent, config)
             VALUES (?::uuid, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Users Report Test\"}'::jsonb)",
            [$cid]
        );
    }
    $db->Execute('INSERT INTO outlet (outletid, outletname, outletstatus, companyid) VALUES (?::uuid, ?, 1, ?::uuid)',
        [$outletA, 'Equipo Sucursal A', $companyId]);
    $db->Execute('INSERT INTO outlet (outletid, outletname, outletstatus, companyid) VALUES (?::uuid, ?, 1, ?::uuid)',
        [$outletB, 'Equipo Sucursal B', $companyB]);

    // type=0 es "usuario del comercio"; contactStatus=0 es dado de baja.
    foreach ([
        [U_ANA,   'Ana Vendedora',   $companyId, $outletA, 1],
        [U_BRUNO, 'Bruno Vendedor',  $companyId, $outletA, 1],
        [U_CARLA, 'Carla De Baja',   $companyId, $outletA, 0],
        [U_EMA,   'Ema Sin Ventas',  $companyId, $outletA, 1],
        [U_BETO,  'Beto Otra Empresa', $companyB, $outletB, 1],
    ] as [$uid, $name, $cid, $oid, $status]) {
        $db->Execute(
            'INSERT INTO contact (contactid, contactname, companyid, outletid, type, contactstatus)
             VALUES (?::uuid, ?, ?::uuid, ?::uuid, 0, ?)',
            [$uid, $name, $cid, $oid, $status]
        );
    }

    foreach ([[ITEM_A, $companyId], [ITEM_B, $companyB]] as [$iid, $cid]) {
        $db->Execute(
            "INSERT INTO item (itemid, itemname, itemsku, itemprice, itemtype, itemstatus,
                               itemcansale, itemtrackinventory, companyid, itemkind)
             VALUES (?::uuid, 'Item de prueba', ?, 1000, 'product', 1, TRUE, FALSE, ?::uuid, 'producto')",
            [$iid, 'EQUIPO-' . substr($iid, -4), $cid]
        );
    }

    $t1 = '0f5e7a10-0000-4000-8000-000000000101';
    $t2 = '0f5e7a10-0000-4000-8000-000000000102';
    $t3 = '0f5e7a10-0000-4000-8000-000000000103';
    $t4 = '0f5e7a10-0000-4000-8000-000000000104';
    $t5 = '0f5e7a10-0000-4000-8000-000000000105';
    $t6 = '0f5e7a10-0000-4000-8000-000000000106';
    $t7 = '0f5e7a10-0000-4000-8000-000000000107';
    $t8 = '0f5e7a10-0000-4000-8000-000000000108';
    $t9 = '0f5e7a10-0000-4000-8000-000000000109';

    // T1 — Ana, 2 líneas sin vendedor de línea.
    $seedTx($t1, $companyId, $outletA, U_ANA, '2026-03-05 10:00:00+00', 0, 150000, null, 25, '001-001');
    $seedLine($t1, $companyId, $outletA, null, '2026-03-05 10:00:00+00', 100000, 10000, 10000, 2);
    $seedLine($t1, $companyId, $outletA, null, '2026-03-05 10:00:00+00',  50000,  5000,     0, 1);

    // T2 — operada por Ana, vendida por Bruno (vendedor de línea).
    $seedTx($t2, $companyId, $outletA, U_ANA, '2026-03-05 18:00:00+00', 0, 200000, null, 26, '001-001');
    $seedLine($t2, $companyId, $outletA, U_BRUNO, '2026-03-05 18:00:00+00', 200000, 20000, 0, 4);

    // T3 — operada por Bruno, con una línea de Ana: un ticket para cada uno.
    $seedTx($t3, $companyId, $outletA, U_BRUNO, '2026-03-06 12:00:00+00', 0, 100000, null, 27, '001-001');
    $seedLine($t3, $companyId, $outletA, U_ANA, '2026-03-06 12:00:00+00', 30000, 3000, 0, 1);
    $seedLine($t3, $companyId, $outletA, null,  '2026-03-06 12:00:00+00', 70000, 7000, 0, 2);

    // T4 — anulada.
    $seedTx($t4, $companyId, $outletA, U_ANA, '2026-03-07 12:00:00+00', 0, 999000, '2026-03-08 09:00:00+00', 28, '001-001');
    $seedLine($t4, $companyId, $outletA, null, '2026-03-07 12:00:00+00', 999000, 99000, 0, 9);

    // T5 — no es venta (type 5).
    $seedTx($t5, $companyId, $outletA, U_ANA, '2026-03-08 12:00:00+00', 5, 500000, null, null, '');
    $seedLine($t5, $companyId, $outletA, null, '2026-03-08 12:00:00+00', 500000, 50000, 0, 5);

    // T6 — vendida por una usuaria dada de baja.
    $seedTx($t6, $companyId, $outletA, U_CARLA, '2026-03-09 12:00:00+00', 0, 400000, null, 29, '001-001');
    $seedLine($t6, $companyId, $outletA, null, '2026-03-09 12:00:00+00', 400000, 40000, 0, 4);

    // T7 — fuera del rango.
    $seedTx($t7, $companyId, $outletA, U_ANA, '2026-02-20 12:00:00+00', 0, 300000, null, 20, '001-001');
    $seedLine($t7, $companyId, $outletA, null, '2026-02-20 12:00:00+00', 300000, 30000, 0, 3);

    // T8 — otra empresa, mismo día.
    $seedTx($t8, $companyB, $outletB, U_BETO, '2026-03-10 12:00:00+00', 0, 700000, null, 30, '002-001');
    $seedLine($t8, $companyB, $outletB, null, '2026-03-10 12:00:00+00', 700000, 70000, 0, 7, ITEM_B);

    // T9 — devolución de Bruno (type 6): entra en negativo.
    $seedTx($t9, $companyId, $outletA, U_BRUNO, '2026-03-10 15:00:00+00', 6, -50000, null, 31, '001-001');
    $seedLine($t9, $companyId, $outletA, null, '2026-03-10 15:00:00+00', -50000, -5000, 0, -1);

    /* ═══ Vista default — NO se puede haber roto ═══════════════════════════ */
    $legacy = $svc->salesByUser($from, $to, $companyId);
    $lAna   = byUser($legacy, U_ANA);
    $lBruno = byUser($legacy, U_BRUNO);
    $lEma   = byUser($legacy, U_EMA);

    check('default: Ana suma 180.000 (T1 + su línea de T3)',
        $lAna !== null && near($lAna['total'], 180000), v($lAna), $failures, $checks);
    check('default: `count` sigue contando LÍNEAS (3 para Ana), no transacciones',
        $lAna !== null && (int) $lAna['count'] === 3, v($lAna['count'] ?? null), $failures, $checks);
    check('default: Bruno suma 220.000 (T2 + su línea de T3 − la devolución T9)',
        $lBruno !== null && near($lBruno['total'], 220000), v($lBruno), $failures, $checks);
    check('default: el usuario activo sin ventas aparece en cero',
        $lEma !== null && near($lEma['total'], 0) && (int) $lEma['count'] === 0, v($lEma), $failures, $checks);
    check('default: la usuaria dada de baja no aparece',
        byUser($legacy, U_CARLA) === null, 'Carla apareció en el reporte', $failures, $checks);
    check('default: el vendedor de la otra empresa no aparece',
        byUser($legacy, U_BETO) === null, 'Beto cruzó el tenant', $failures, $checks);

    /* ═══ view=summary ════════════════════════════════════════════════════ */
    try {
        $sum = $svc->summary($from, $to, $companyId);
        $tot = $sum['totals'];
        $rk  = $sum['ranking'];
        $rAna   = byUser($rk, U_ANA);
        $rBruno = byUser($rk, U_BRUNO);

        check('summary: total vendido = 400.000', near($tot['total'], 400000), v($tot), $failures, $checks);
        check('summary: comisiones = 40.000', near($tot['comission'], 40000), v($tot), $failures, $checks);
        check('summary: descuentos = 10.000', near($tot['discount'], 10000), v($tot), $failures, $checks);
        check('summary: unidades = 9', near($tot['usold'], 9), v($tot), $failures, $checks);
        check('summary: tickets DISTINTOS = 4 (T1, T2, T3, T9 — no 6 líneas)',
            (int) $tot['tickets'] === 4, v($tot['tickets']), $failures, $checks);
        check('summary: ticket promedio = 100.000 (400.000 / 4 tickets distintos)',
            nearPct($tot['avgTicket'], 100000), v($tot['avgTicket']), $failures, $checks);
        check('summary: vendedores con actividad = 2 (Ema, sin ventas, NO está en el ranking)',
            (int) $tot['sellers'] === 2 && byUser($rk, U_EMA) === null, v($tot['sellers']), $failures, $checks);

        check('summary: el ranking arranca por Bruno (220.000 > 180.000)',
            isset($rk[0]) && $rk[0]['userId'] === U_BRUNO, v($rk[0]['name'] ?? null), $failures, $checks);
        check('summary: Ana tiene 2 tickets (T1 y T3), no 3 líneas',
            $rAna !== null && (int) $rAna['tickets'] === 2, v($rAna), $failures, $checks);
        check('summary: Bruno tiene 3 tickets (T2, T3 y la devolución T9)',
            $rBruno !== null && (int) $rBruno['tickets'] === 3, v($rBruno), $failures, $checks);
        check('summary: los tickets por vendedor (5) superan al global (4) — T3 es de los dos',
            $rAna !== null && $rBruno !== null
                && ((int) $rAna['tickets'] + (int) $rBruno['tickets']) === 5 && (int) $tot['tickets'] === 4,
            'ana=' . v($rAna['tickets'] ?? null) . ' bruno=' . v($rBruno['tickets'] ?? null),
            $failures, $checks);
        check('summary: ticket promedio de Ana = 90.000 (180.000 / 2)',
            $rAna !== null && nearPct($rAna['avgTicket'], 90000), v($rAna['avgTicket'] ?? null), $failures, $checks);
        check('summary: % de descuento de Ana = 5,56% (10.000 sobre 180.000)',
            $rAna !== null && nearPct($rAna['discountPct'], 5.5556), v($rAna['discountPct'] ?? null), $failures, $checks);
        check('summary: Bruno sin descuentos queda en 0%, no en NULL',
            $rBruno !== null && nearPct($rBruno['discountPct'], 0), v($rBruno['discountPct'] ?? null), $failures, $checks);
        check('summary: la comisión de Bruno es 22.000 — la devolución RESTA 5.000',
            $rBruno !== null && near($rBruno['comission'], 22000), v($rBruno['comission'] ?? null), $failures, $checks);

        // Serie diaria: 3 días con actividad, 5 pares (día, vendedor).
        $daily = $sum['daily'];
        check('summary: la serie diaria trae 5 filas (día × vendedor)',
            count($daily) === 5, 'obtenido ' . count($daily) . ': ' . json_encode($daily), $failures, $checks);
        $find = static function (array $daily, string $date, string $uid): ?array {
            foreach ($daily as $d) {
                if ($d['date'] === $date && $d['userId'] === $uid) return $d;
            }
            return null;
        };
        $d0501 = $find($daily, '2026-03-05', U_ANA);
        $d0502 = $find($daily, '2026-03-05', U_BRUNO);
        $d0610 = $find($daily, '2026-03-10', U_BRUNO);
        check('summary: el 05/03 Ana hizo 150.000 (las dos líneas de T1)',
            $d0501 !== null && near($d0501['total'], 150000), v($d0501), $failures, $checks);
        check('summary: el 05/03 Bruno hizo 200.000 (T2, operada por Ana)',
            $d0502 !== null && near($d0502['total'], 200000), v($d0502), $failures, $checks);
        check('summary: el 10/03 Bruno queda en −50.000 (la devolución)',
            $d0610 !== null && near($d0610['total'], -50000), v($d0610), $failures, $checks);
        check('summary: la serie no trae días sin ventas ni la venta anulada',
            $find($daily, '2026-03-07', U_ANA) === null && $find($daily, '2026-03-08', U_ANA) === null,
            json_encode($daily), $failures, $checks);
    } catch (\Throwable $e) {
        check('summary() corre sin error', false, get_class($e) . ': ' . $e->getMessage(), $failures, $checks);
    }

    /* ═══ view=commissions ════════════════════════════════════════════════ */
    try {
        $com = $svc->commissions($from, $to, $companyId);
        $sellers = $com['sellers'];

        check('commissions: dos vendedores con actividad',
            count($sellers) === 2, 'obtenido ' . count($sellers), $failures, $checks);
        check('commissions: ordena por comisión desc — Bruno primero (22.000)',
            isset($sellers[0]) && $sellers[0]['userId'] === U_BRUNO && near($sellers[0]['comission'], 22000),
            v($sellers[0] ?? null), $failures, $checks);

        $cAna = byUser($sellers, U_ANA);
        check('commissions: Ana totaliza 18.000 de comisión y 180.000 vendidos',
            $cAna !== null && near($cAna['comission'], 18000) && near($cAna['total'], 180000),
            v($cAna), $failures, $checks);
        check('commissions: Ana tiene 2 filas — las 2 líneas de T1 son UNA sola',
            $cAna !== null && count($cAna['rows']) === 2 && (int) $cAna['tickets'] === 2,
            json_encode($cAna['rows'] ?? []), $failures, $checks);

        $rowT1 = null;
        foreach (($cAna['rows'] ?? []) as $r) {
            if (near($r['total'], 150000)) $rowT1 = $r;
        }
        check('commissions: la fila de T1 suma las dos líneas (150.000 y 15.000 de comisión)',
            $rowT1 !== null && near($rowT1['comission'], 15000), v($rowT1), $failures, $checks);
        check('commissions: el documento sale formateado, no concatenado a mano',
            $rowT1 !== null && $rowT1['invoiceNo'] === '001-001-0000025', v($rowT1['invoiceNo'] ?? null),
            $failures, $checks);
        check('commissions: las filas de un vendedor vienen de más nueva a más vieja',
            $cAna !== null && count($cAna['rows']) === 2
                && strcmp((string) $cAna['rows'][0]['date'], (string) $cAna['rows'][1]['date']) > 0,
            json_encode(array_column($cAna['rows'] ?? [], 'date')), $failures, $checks);

        $cBruno = byUser($sellers, U_BRUNO);
        check('commissions: Bruno tiene 3 filas, una de ellas la devolución en negativo',
            $cBruno !== null && count($cBruno['rows']) === 3
                && count(array_filter($cBruno['rows'], static fn($r) => near($r['comission'], -5000))) === 1,
            json_encode($cBruno['rows'] ?? []), $failures, $checks);

        check('commissions: los totales cierran contra el summary (400.000 / 40.000)',
            near($com['totals']['total'], 400000) && near($com['totals']['comission'], 40000),
            v($com['totals']), $failures, $checks);
        check('commissions: los tickets del total son 5 — la suma por vendedor, no el distinct global',
            (int) $com['totals']['tickets'] === 5, v($com['totals']['tickets']), $failures, $checks);
        check('commissions: ni la anulada, ni la de otra empresa, ni la de la usuaria de baja',
            $cAna !== null
                && count(array_filter($cAna['rows'], static fn($r) => near($r['total'], 999000))) === 0
                && byUser($sellers, U_CARLA) === null && byUser($sellers, U_BETO) === null,
            json_encode($sellers), $failures, $checks);
    } catch (\Throwable $e) {
        check('commissions() corre sin error', false, get_class($e) . ': ' . $e->getMessage(), $failures, $checks);
    }

    /* ═══ Rango vacío — no puede explotar ni inventar promedios ═══════════ */
    try {
        $empty = $svc->summary('2025-01-01 00:00:00', '2025-01-31 23:59:59', $companyId);
        check('summary: un período sin ventas devuelve ceros, no una división por cero',
            $empty['ranking'] === [] && (int) $empty['totals']['tickets'] === 0
                && near($empty['totals']['avgTicket'], 0) && $empty['daily'] === [],
            json_encode($empty['totals']), $failures, $checks);
        $emptyC = $svc->commissions('2025-01-01 00:00:00', '2025-01-31 23:59:59', $companyId);
        check('commissions: un período sin ventas devuelve la lista vacía',
            $emptyC['sellers'] === [] && near($emptyC['totals']['comission'], 0),
            json_encode($emptyC), $failures, $checks);
    } catch (\Throwable $e) {
        check('las vistas soportan un rango vacío', false, get_class($e) . ': ' . $e->getMessage(), $failures, $checks);
    }
} finally {
    $db->Execute("SET TIME ZONE 'UTC'");
    $cleanup();
}

harnessFinish($failures, $checks);
