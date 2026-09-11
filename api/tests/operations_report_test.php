<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Arnés del reporte de OPERACIÓN (`OperationsService`, `GET /v1/reports/operations`).
 *
 * El service se escribió sin correr contra Postgres. Este arnés siembra órdenes
 * con su log de eventos y sesiones de espacio a mano —con horarios exactos— y
 * compara cada número de cada bloque contra el valor calculado a mano abajo.
 *
 * Cada bloque se pide POR SEPARADO (`include=[bloque]`) y dentro de un
 * try/catch: un error de SQL en `volume` no tiene que esconder lo que pasa en
 * `spaces`. Así el arnés sirve también como inventario de qué está roto.
 *
 * ── Los casos que importan ───────────────────────────────────────────────────
 *
 *   O1  ciclo completo: enviada → en proceso → lista → entregada.
 *   O2  SALTO: enviada → entregada en un paso (la máquina lo permite,
 *       `OrderCoreService::ORDER_TRANSITIONS['sent']`). Cuenta como entregada
 *       pero NO tiene demora por etapa: es el caso que la cobertura declara.
 *   O3  RE-TRABAJO: lista → en proceso (orden) + un ítem lista → en preparación,
 *       y vuelve a lista. La etapa "en proceso → lista" mide hasta la ÚLTIMA
 *       vez que quedó lista: el tiempo rehecho es trabajo, no espera.
 *   O4  quedó en proceso (abierta).
 *   O5  nació `open`, se envió 10 min después y se cobró desde `ready`
 *       (`markPaid` la cierra sin pasar por entregada). La cola se mide desde
 *       el ENVÍO, no desde la creación: antes de enviarse nadie la veía.
 *   O6  cancelada: fuera de etapas y demanda, dentro del volumen.
 *   O7  SIN EVENTOS (orden histórica sin backfill): tiene que estar en el
 *       denominador de la cobertura — si no, la cobertura se miente a favor.
 *   O8  salteó "en proceso" (enviada → lista), volvió a proceso y a lista.
 *       Con `MIN(lista)` la etapa "en proceso → lista" daba NEGATIVA.
 *   O9  OTRA empresa, mismo horario que O1. No debe aparecer en nada.
 *   O10 otra sucursal de la misma empresa (salto enviada → entregada). Entra
 *       en el consolidado, sale con el filtro de sucursal.
 *   O11 fuera del rango.
 *
 *   Espacios: S1/S3 en T1, S2/S4 en T2 (S4 sigue abierta), S5 FUSIONADA en otra
 *   (no es una ocupación: infla la rotación), S6 cancelada, S7 de otra empresa,
 *   S8 fuera del rango. T3 nunca se usó y T-decor es una pared.
 *
 * Uso: `bash api/tests/run_operations_report_test.sh` (levanta Postgres descartable).
 */

$companyId = '0f5e7a10-0000-4000-8000-000000000001';
$companyB  = '0f5e7a10-0000-4000-8000-000000000002';
$outletA1  = '0f5e7a10-0000-4000-8000-000000000011';
$outletA2  = '0f5e7a10-0000-4000-8000-000000000012';
$outletB1  = '0f5e7a10-0000-4000-8000-000000000013';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA1);
define('USER_ID',    '0f5e7a10-0000-4000-8000-000000000021');

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Orders/OrderCoreService.php';

use Punto\Api\Orders\OrderCoreService;
use Punto\Api\Reports\OperationsService;
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

function near(?float $a, float $b): bool
{
    return $a !== null && abs($a - $b) < 0.05;
}

function v(mixed $x): string
{
    return var_export($x, true);
}

// ── Fixtures ────────────────────────────────────────────────────────────────

const T1 = '0f5e7a10-0000-4000-8000-0000000000a1';
const T2 = '0f5e7a10-0000-4000-8000-0000000000a2';
const T3 = '0f5e7a10-0000-4000-8000-0000000000a3';
const TD = '0f5e7a10-0000-4000-8000-0000000000a4';
const TB = '0f5e7a10-0000-4000-8000-0000000000a5';

const S1 = '0f5e7a10-0000-4000-8000-0000000000b1';
const S2 = '0f5e7a10-0000-4000-8000-0000000000b2';
const S3 = '0f5e7a10-0000-4000-8000-0000000000b3';
const S4 = '0f5e7a10-0000-4000-8000-0000000000b4';
const S5 = '0f5e7a10-0000-4000-8000-0000000000b5';
const S6 = '0f5e7a10-0000-4000-8000-0000000000b6';
const S7 = '0f5e7a10-0000-4000-8000-0000000000b7';
const S8 = '0f5e7a10-0000-4000-8000-0000000000b8';

/**
 * Alta de una orden con su log. `$events` = [[from, to, 'HH:MM', scope?], ...]
 * sobre el mismo día que `$created`. Timestamps en UTC explícito: la sesión de
 * PG se fija en UTC para que las horas esperadas sean las que están escritas.
 */
function seedOrder(string $companyId, string $outletId, string $orderId, string $status,
                   string $created, array $events, ?string $spaceSessionId = null): void
{
    global $db;
    $db->Execute(
        "INSERT INTO pos_order (orderid, companyid, outletid, status, created_at, spacesessionid)
         VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?::timestamptz, ?::uuid)",
        [$orderId, $companyId, $outletId, $status, $created . '+00', $spaceSessionId]
    );
    $day = substr($created, 0, 10);
    foreach ($events as $e) {
        [$from, $to, $hhmm] = $e;
        $scope = $e[3] ?? 'order';
        $db->Execute(
            "INSERT INTO pos_order_event (companyid, outletid, orderid, scope, from_status, to_status, actor_kind, created_at)
             VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, 'system', ?::timestamptz)",
            [$companyId, $outletId, $orderId, $scope, $from, $to, "$day $hhmm:00+00"]
        );
    }
}

function seedSession(string $companyId, string $outletId, string $sessionId, string $tableId,
                     string $status, ?int $guests, string $opened, ?string $closed,
                     ?string $mergedInto = null): void
{
    global $db;
    $db->Execute(
        "INSERT INTO space_session (sessionid, companyid, outletid, tableid, status, guests, opened_at, closed_at, mergedinto)
         VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?, ?::timestamptz, ?::timestamptz, ?::uuid)",
        [$sessionId, $companyId, $outletId, $tableId, $status, $guests, $opened . '+00',
         $closed === null ? null : $closed . '+00', $mergedInto]
    );
}

function oid(int $n): string
{
    return sprintf('0f5e7a10-0000-4000-8000-0000000001%02d', $n);
}

$cleanup = static function () use ($companyId, $companyB): void {
    global $db;
    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute('DELETE FROM pos_order_event WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM pos_order       WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM space_session   WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM space           WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM space_sector    WHERE companyid = ?::uuid', [$cid]);
        $db->Execute('DELETE FROM contact WHERE companyId = ?', [$cid]);
        $db->Execute('DELETE FROM outlet  WHERE companyId = ?', [$cid]);
        $db->Execute('DELETE FROM company WHERE companyId = ?', [$cid]);
    }
};

try {
    $cleanup();
    $db->Execute("SET TIME ZONE 'UTC'");

    foreach ([$companyId, $companyB] as $cid) {
        $db->Execute(
            "INSERT INTO company (companyId, status, plan, balance, isParent, config)
             VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Operations Test\"}'::jsonb)",
            [$cid]
        );
    }
    foreach ([[$outletA1, $companyId], [$outletA2, $companyId], [$outletB1, $companyB]] as [$o, $c]) {
        $db->Execute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
            [$o, 'Ops Sucursal', $c]);
    }
    // Sucursal A1 con ubicación (origen del mapa de entrega del detalle) y el
    // responsable de O1 como contacto — find() tiene que resolver los dos.
    $db->Execute('UPDATE outlet SET lat = -25.3000000, lng = -57.6000000 WHERE outletId = ?', [$outletA1]);
    $db->Execute('INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
                  VALUES (?, ?, ?, ?, 0, 1)', [USER_ID, 'Responsable Uno', $companyId, $outletA1]);

    $sectorA = '0f5e7a10-0000-4000-8000-0000000000c1';
    $sectorB = '0f5e7a10-0000-4000-8000-0000000000c2';
    $db->Execute("INSERT INTO space_sector (sectorid, companyid, outletid, name) VALUES (?::uuid, ?::uuid, ?::uuid, 'Sector A')",
        [$sectorA, $companyId, $outletA1]);
    $db->Execute("INSERT INTO space_sector (sectorid, companyid, outletid, name) VALUES (?::uuid, ?::uuid, ?::uuid, 'Sector B')",
        [$sectorB, $companyB, $outletB1]);
    foreach ([
        [T1, $companyId, $outletA1, $sectorA, 'Espacio 1', 'square', 1],
        [T2, $companyId, $outletA1, $sectorA, 'Espacio 2', 'round', 2],
        [T3, $companyId, $outletA1, $sectorA, 'Espacio 3', 'rect', 3],
        [TD, $companyId, $outletA1, $sectorA, 'Pared',     'decor_wall', 4],
        [TB, $companyB,  $outletB1, $sectorB, 'Ajeno',     'square', 1],
    ] as [$t, $c, $o, $s, $name, $shape, $sort]) {
        $db->Execute(
            "INSERT INTO space (tableid, companyid, outletid, sectorid, name, shape, sort, status)
             VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?, ?, 1)",
            [$t, $c, $o, $s, $name, $shape, $sort]
        );
    }

    // Semana del lunes 2026-03-02 al domingo 2026-03-08.
    seedSession($companyId, $outletA1, S1, T1, 'closed', 4,    '2026-03-03 12:00:00', '2026-03-03 13:30:00');
    seedSession($companyId, $outletA1, S2, T2, 'closed', null, '2026-03-02 09:50:00', '2026-03-02 10:40:00');
    seedSession($companyId, $outletA1, S3, T1, 'closed', 2,    '2026-03-04 20:30:00', '2026-03-04 22:10:00');
    seedSession($companyId, $outletA1, S4, T2, 'open',   3,    '2026-03-07 19:00:00', null);
    seedSession($companyId, $outletA1, S5, T3, 'closed', 5,    '2026-03-05 13:00:00', '2026-03-05 13:20:00', S1);
    seedSession($companyId, $outletA1, S6, T3, 'cancelled', 6, '2026-03-05 15:00:00', '2026-03-05 15:05:00');
    seedSession($companyB,  $outletB1, S7, TB, 'closed', 9,    '2026-03-02 10:00:00', '2026-03-02 11:00:00');
    seedSession($companyId, $outletA1, S8, T1, 'closed', 8,    '2026-03-10 10:00:00', '2026-03-10 11:00:00');

    // O1 trae además eventos de ÍTEM con los MISMOS nombres de estado ('ready',
    // 'delivered') en horarios distintos a los de la orden. Si el cálculo no
    // filtra `scope='order'`, la entrega del ítem (10:24) le gana a la de la
    // orden (10:25) y la espera "lista → entregada" cambia.
    seedOrder($companyId, $outletA1, oid(1), 'delivered', '2026-03-02 10:00:00', [
        [null, 'sent', '10:00'], ['sent', 'in_progress', '10:05'],
        ['pending', 'preparing', '10:06', 'item'], ['preparing', 'ready', '10:18', 'item'],
        ['in_progress', 'ready', '10:20'],
        ['ready', 'delivered', '10:24', 'item'],
        ['ready', 'delivered', '10:25'],
    ], S2);
    $db->Execute(
        "UPDATE pos_order SET userid = ?::uuid, fulfillment = 'delivery', deliverylat = -25.2900000, deliverylng = -57.5800000
          WHERE orderid = ?::uuid",
        [USER_ID, oid(1)]
    );
    seedOrder($companyId, $outletA1, oid(2), 'delivered', '2026-03-02 10:30:00', [
        [null, 'sent', '10:30'], ['sent', 'delivered', '11:30'],
    ]);
    seedOrder($companyId, $outletA1, oid(3), 'delivered', '2026-03-03 12:00:00', [
        [null, 'sent', '12:00'], ['sent', 'in_progress', '12:10'],
        ['in_progress', 'ready', '12:30'],
        ['ready', 'in_progress', '12:35'],
        ['ready', 'preparing', '12:35', 'item'],
        ['in_progress', 'ready', '12:50'], ['ready', 'delivered', '13:00'],
    ], S1);
    seedOrder($companyId, $outletA1, oid(4), 'in_progress', '2026-03-04 18:00:00', [
        [null, 'sent', '18:00'], ['sent', 'in_progress', '18:15'],
    ], S1);
    seedOrder($companyId, $outletA1, oid(5), 'closed', '2026-03-04 18:20:00', [
        [null, 'open', '18:20'], ['open', 'sent', '18:30'], ['sent', 'in_progress', '18:32'],
        ['in_progress', 'ready', '18:40'], ['ready', 'closed', '18:45'],
    ]);
    seedOrder($companyId, $outletA1, oid(6), 'cancelled', '2026-03-05 09:00:00', [
        [null, 'sent', '09:00'], ['sent', 'cancelled', '09:10'],
    ]);
    seedOrder($companyId, $outletA1, oid(7), 'sent', '2026-03-05 09:30:00', []);
    seedOrder($companyId, $outletA1, oid(8), 'delivered', '2026-03-06 11:00:00', [
        [null, 'sent', '11:00'], ['sent', 'ready', '11:00'],
        ['ready', 'in_progress', '11:05'], ['in_progress', 'ready', '11:10'],
        ['ready', 'delivered', '11:15'],
    ]);
    seedOrder($companyB, $outletB1, oid(9), 'delivered', '2026-03-02 10:00:00', [
        [null, 'sent', '10:00'], ['sent', 'in_progress', '10:01'],
        ['in_progress', 'ready', '10:02'], ['ready', 'in_progress', '10:03'],
        ['in_progress', 'ready', '10:04'], ['ready', 'delivered', '10:05'],
    ], S7);
    seedOrder($companyId, $outletA2, oid(10), 'delivered', '2026-03-07 20:00:00', [
        [null, 'sent', '20:00'], ['sent', 'delivered', '20:40'],
    ]);
    seedOrder($companyId, $outletA1, oid(11), 'delivered', '2026-03-09 10:00:00', [
        [null, 'sent', '10:00'], ['sent', 'delivered', '10:30'],
    ]);

    $from = '2026-03-02 00:00:00';
    $to   = '2026-03-08 23:59:59';
    $roc  = Roc::build($companyId, '');
    $svc  = new OperationsService();

    /** Un bloque, aislado: si su SQL explota, se reporta y el resto sigue. */
    $block = static function (string $name, string $rocFrag) use ($svc, $from, $to, $companyId, &$failures, &$checks): ?array {
        try {
            $r = $svc->report($from, $to, $rocFrag, $companyId, [$name]);
            return $r[$name] ?? null;
        } catch (\Throwable $e) {
            check("bloque '$name' corre sin error de SQL", false,
                get_class($e) . ': ' . $e->getMessage(), $failures, $checks);
            return null;
        }
    };

    // ── VOLUMEN ─────────────────────────────────────────────────────────────
    $vol = $block('volume', $roc);
    if ($vol !== null) {
        check('volume.total = 9 (O1-O8 + O10; ni la otra empresa ni fuera de rango)',
            ($vol['total'] ?? null) === 9, 'obtenido ' . v($vol['total'] ?? null), $failures, $checks);
        check('volume.cancelled = 1', ($vol['cancelled'] ?? null) === 1, 'obtenido ' . v($vol['cancelled'] ?? null), $failures, $checks);
        check('volume.delivered = 5', ($vol['delivered'] ?? null) === 5, 'obtenido ' . v($vol['delivered'] ?? null), $failures, $checks);
        check('volume.completed = 6 (entregadas + cobradas: markPaid cierra sin pasar por entregada)',
            ($vol['completed'] ?? null) === 6, 'obtenido ' . v($vol['completed'] ?? null), $failures, $checks);
        check('volume.open = 2 (O4 en proceso, O7 enviada)', ($vol['open'] ?? null) === 2, 'obtenido ' . v($vol['open'] ?? null), $failures, $checks);
        check('volume.spacesUsed = 2 (T1 y T2, vía la sesión de la orden)',
            ($vol['spacesUsed'] ?? null) === 2, 'obtenido ' . v($vol['spacesUsed'] ?? null), $failures, $checks);
    }

    // ── ETAPAS ──────────────────────────────────────────────────────────────
    $st = $block('stages', $roc);
    if ($st !== null) {
        $cov = $st['coverage'] ?? [];
        check('stages.ordersTotal = 8 (incluye O7 SIN eventos: es denominador de la cobertura)',
            ($st['ordersTotal'] ?? null) === 8, 'obtenido ' . v($st['ordersTotal'] ?? null), $failures, $checks);
        check('coverage.total = 8', ($cov['total'] ?? null) === 8, 'obtenido ' . v($cov['total'] ?? null), $failures, $checks);
        check('coverage.withProgress = 5 (O1 O3 O4 O5 O8)', ($cov['withProgress'] ?? null) === 5, 'obtenido ' . v($cov['withProgress'] ?? null), $failures, $checks);
        check('coverage.withReady = 4 (O1 O3 O5 O8)', ($cov['withReady'] ?? null) === 4, 'obtenido ' . v($cov['withReady'] ?? null), $failures, $checks);
        check('coverage.withDelivered = 5 (O1 O2 O3 O8 O10)', ($cov['withDelivered'] ?? null) === 5, 'obtenido ' . v($cov['withDelivered'] ?? null), $failures, $checks);
        check('coverage.skipped = 2 (O2 y O10 saltaron a entregada sin etapas)',
            ($cov['skipped'] ?? null) === 2, 'obtenido ' . v($cov['skipped'] ?? null), $failures, $checks);
        check('coverage.fullyTracked = 3 (O1 O3 O8 con las tres marcas)',
            ($cov['fullyTracked'] ?? null) === 3, 'obtenido ' . v($cov['fullyTracked'] ?? null), $failures, $checks);

        // Cola desde el ENVÍO: 5 + 10 + 15 + 2 + 5 = 37 / 5
        check('avgToProgress = 7.4 min (cola medida desde el envío, no desde la creación)',
            near($st['avgToProgress'] ?? null, 7.4), 'obtenido ' . v($st['avgToProgress'] ?? null), $failures, $checks);
        // Hasta la ÚLTIMA vez lista: 15 + 40 + 8 + 5 = 68 / 4
        check('avgProgressToReady = 17.0 min (re-trabajo es proceso; nunca negativo)',
            near($st['avgProgressToReady'] ?? null, 17.0), 'obtenido ' . v($st['avgProgressToReady'] ?? null), $failures, $checks);
        // 5 + 10 + 5 = 20 / 3
        check('avgReadyToDelivered = 6.7 min', near($st['avgReadyToDelivered'] ?? null, 6.7),
            'obtenido ' . v($st['avgReadyToDelivered'] ?? null), $failures, $checks);
        // 25 + 60 + 60 + 15 + 40 = 200 / 5 ; mediana de [15,25,40,60,60] = 40
        check('avgTotal = 40.0 min (envío → entregada)', near($st['avgTotal'] ?? null, 40.0),
            'obtenido ' . v($st['avgTotal'] ?? null), $failures, $checks);
        check('medianTotal = 40.0 min', near($st['medianTotal'] ?? null, 40.0),
            'obtenido ' . v($st['medianTotal'] ?? null), $failures, $checks);
        check('reworks = 2 (O3 y O8 volvieron de lista a proceso; O9 es de otra empresa)',
            ($st['reworks'] ?? null) === 2, 'obtenido ' . v($st['reworks'] ?? null), $failures, $checks);
        check('reworkItems = 1 (la línea de O3 devuelta a preparación)',
            ($st['reworkItems'] ?? null) === 1, 'obtenido ' . v($st['reworkItems'] ?? null), $failures, $checks);
        check('ordersWithRework = 2', ($st['ordersWithRework'] ?? null) === 2,
            'obtenido ' . v($st['ordersWithRework'] ?? null), $failures, $checks);
        // El denominador EXACTO de cada promedio.
        $ss = $cov['stageSamples'] ?? [];
        check('stageSamples = {toProgress:5, progressToReady:4, readyToDelivered:3, total:5}',
            $ss === ['toProgress' => 5, 'progressToReady' => 4, 'readyToDelivered' => 3, 'total' => 5],
            'obtenido ' . json_encode($ss), $failures, $checks);
    }

    // ── DEMANDA ─────────────────────────────────────────────────────────────
    $toMap = static function (array $buckets): array {
        $m = [];
        foreach ($buckets as $b) { $m[(int) $b['bucket']] = (int) $b['orders']; }
        ksort($m);
        return $m;
    };
    $dem = $block('demand', $roc);
    if ($dem !== null) {
        $h = $toMap($dem['byHour'] ?? []);
        check('demand.byHour (UTC) = {9:1, 10:2, 11:1, 12:1, 18:2, 20:1} — sin la cancelada',
            $h === [9 => 1, 10 => 2, 11 => 1, 12 => 1, 18 => 2, 20 => 1], 'obtenido ' . json_encode($h), $failures, $checks);
        $w = $toMap($dem['byWeekday'] ?? []);
        check('demand.byWeekday (ISODOW, 1=lunes) = {1:2, 2:1, 3:2, 4:1, 5:1, 6:1}',
            $w === [1 => 2, 2 => 1, 3 => 2, 4 => 1, 5 => 1, 6 => 1], 'obtenido ' . json_encode($w), $failures, $checks);
    }

    // La hora sale en la zona de la SESIÓN, que es lo que deja TenantClock::apply().
    $db->Execute("SET TIME ZONE 'America/Bogota'");
    $demTz = $block('demand', $roc);
    $db->Execute("SET TIME ZONE 'UTC'");
    if ($demTz !== null) {
        $h = $toMap($demTz['byHour'] ?? []);
        check('demand.byHour en hora del tenant (Bogotá, UTC-5): las de las 10 UTC caen a las 5',
            ($h[5] ?? 0) === 2 && !isset($h[10]), 'obtenido ' . json_encode($h), $failures, $checks);
    }

    // ── ESPACIOS ────────────────────────────────────────────────────────────
    $sp = $block('spaces', $roc);
    if ($sp !== null) {
        $cov = $sp['coverage'] ?? [];
        check('spaces.sessions = 4 (S1-S4; sin fusionada, cancelada, ajena ni fuera de rango)',
            ($sp['sessions'] ?? null) === 4, 'obtenido ' . v($sp['sessions'] ?? null), $failures, $checks);
        check('spaces.merged = 1 (se informa aparte, no suma ocupación)',
            ($sp['merged'] ?? null) === 1, 'obtenido ' . v($sp['merged'] ?? null), $failures, $checks);
        check('coverage.withGuests = 3 (S2 no cargó personas)', ($cov['withGuests'] ?? null) === 3,
            'obtenido ' . v($cov['withGuests'] ?? null), $failures, $checks);
        check('coverage.closed = 3 (S4 sigue abierta)', ($cov['closed'] ?? null) === 3,
            'obtenido ' . v($cov['closed'] ?? null), $failures, $checks);
        check('avgGuests = 3.0 ((4+2+3)/3, el NULL NO es cero)', near($sp['avgGuests'] ?? null, 3.0),
            'obtenido ' . v($sp['avgGuests'] ?? null), $failures, $checks);
        check('avgMinutes = 80.0 ((90+50+100)/3, solo cerradas)', near($sp['avgMinutes'] ?? null, 80.0),
            'obtenido ' . v($sp['avgMinutes'] ?? null), $failures, $checks);

        // Matriz espacio × hora: una sesión CERRADA ocupa cada hora que tocó,
        // no solo la de apertura — S3 (20:30-22:10) pinta 20, 21 y 22. Una
        // ABIERTA (S4) no tiene fin conocido: pinta solo su hora de apertura.
        // Estirarla hasta el fin del rango pintaría el día entero con una
        // sesión que alguien se olvidó de cerrar.
        $cells = [];
        foreach ($sp['heatmap'] ?? [] as $c) {
            $cells[$c['spaceName'] . '@' . $c['hour']] = (int) $c['sessions'];
        }
        ksort($cells);
        $expected = [
            'Espacio 1@12' => 1, 'Espacio 1@13' => 1,
            'Espacio 1@20' => 1, 'Espacio 1@21' => 1, 'Espacio 1@22' => 1,
            'Espacio 2@10' => 1, 'Espacio 2@19' => 1,
            'Espacio 2@9'  => 1,
        ];
        ksort($expected);
        check('heatmap = cada hora ocupada por espacio (S4 abierta: solo su hora de apertura)',
            $cells === $expected, "obtenido\n     " . json_encode($cells) . "\n     esperado\n     " . json_encode($expected),
            $failures, $checks);

        $names = array_column($sp['spaceList'] ?? [], 'spaceName');
        check('spaceList trae los espacios activos, incluido el que nunca se usó, sin la pared',
            $names === ['Espacio 1', 'Espacio 2', 'Espacio 3'], 'obtenido ' . json_encode($names), $failures, $checks);
    }

    // ── SUCURSAL ────────────────────────────────────────────────────────────
    $rocA1 = Roc::build($companyId, $outletA1);
    $volA1 = $block('volume', $rocA1);
    if ($volA1 !== null) {
        check('con filtro de sucursal A1, volume.total = 8 (sale O10 de A2)',
            ($volA1['total'] ?? null) === 8, 'obtenido ' . v($volA1['total'] ?? null), $failures, $checks);
    }
    $stA1 = $block('stages', $rocA1);
    if ($stA1 !== null) {
        check('con filtro A1, coverage.skipped = 1 (solo O2)',
            ($stA1['coverage']['skipped'] ?? null) === 1, 'obtenido ' . v($stA1['coverage']['skipped'] ?? null), $failures, $checks);
    }
    $rocA2 = Roc::build($companyId, $outletA2);
    $spA2 = $block('spaces', $rocA2);
    if ($spA2 !== null) {
        check('con filtro A2 (sin espacios), spaces vacío y sin heatmap',
            ($spA2['sessions'] ?? null) === 0 && ($spA2['heatmap'] ?? null) === [] && ($spA2['spaceList'] ?? null) === [],
            'obtenido ' . json_encode($spA2), $failures, $checks);
    }

    // ── Detalle de la orden (find): sucursal propia + responsable ──────────
    try {
        $core = new OrderCoreService($db);
        $d = $core->find($companyId, oid(1));
        check('find() trae la sucursal DE LA ORDEN con su ubicación (origen del mapa)',
            ($d['outletName'] ?? null) === 'Ops Sucursal'
                && near($d['outletLat'] ?? null, -25.3) && near($d['outletLng'] ?? null, -57.6),
            'obtenido ' . json_encode(array_intersect_key($d ?? [], array_flip(['outletName', 'outletLat', 'outletLng']))),
            $failures, $checks);
        check('find() trae el nombre del responsable', ($d['userName'] ?? null) === 'Responsable Uno',
            'obtenido ' . v($d['userName'] ?? null), $failures, $checks);
        check('find() mantiene el destino snapshoteado de la entrega',
            near($d['deliveryLat'] ?? null, -25.29) && ($d['fulfillment'] ?? null) === 'delivery',
            'obtenido ' . v($d['deliveryLat'] ?? null), $failures, $checks);
        $timeline = array_values(array_filter($d['events'] ?? [], static fn ($e) => $e['scope'] === 'order'));
        check('find() trae la línea de tiempo de la orden (4 eventos de orden en O1)',
            count($timeline) === 4, 'obtenido ' . count($timeline), $failures, $checks);
        $d2 = $core->find($companyId, oid(2));
        // `array_key_exists` y no `??`: `??` trata el null como ausente y el
        // check nunca vería el null que se quiere verificar.
        check('find() sin responsable: la clave viene y vale null, no string vacío',
            is_array($d2) && array_key_exists('userName', $d2) && $d2['userName'] === null,
            'obtenido ' . v(is_array($d2) ? ($d2['userName'] ?? '<null>') : $d2), $failures, $checks);
        check('find() de otra empresa no se ve', $core->find($companyId, oid(9)) === null,
            'la orden de la empresa B fue visible desde A', $failures, $checks);
    } catch (\Throwable $e) {
        check('find() corre sin error', false, get_class($e) . ': ' . $e->getMessage(), $failures, $checks);
    }

    // ── Todos los bloques juntos (lo que pide la pantalla) ─────────────────
    try {
        $all = $svc->report($from, $to, $roc, $companyId, []);
        check('report() sin include devuelve los cuatro bloques',
            array_keys($all) === ['volume', 'stages', 'demand', 'spaces'], 'claves ' . json_encode(array_keys($all)),
            $failures, $checks);
    } catch (\Throwable $e) {
        check('report() sin include corre', false, $e->getMessage(), $failures, $checks);
    }
} finally {
    $db->Execute("SET TIME ZONE 'UTC'");
    $cleanup();
}

harnessFinish($failures, $checks);
