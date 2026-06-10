<?php
/**
 * BFF — Reporte Resumen de Ventas.
 *
 *   GET /bff/reports/summary.php?from=<datetime>&to=<datetime>&view=<vista>
 *
 *   view (default 'kpis'):
 *     kpis  → KPIs del período actual (crudos) + comparación vs período anterior (datos).
 *     chart → series del gráfico de ingresos (números crudos + fechas ISO + promedio crudo).
 *     hours → ventas por hora (horas 0-23 crudas + totales).
 *     byday → filas crudas por día (pestaña "Por Día").
 *
 * El BFF compone los DERIVADOS (netSales, totales, deltas, margin, byweek, alineación del
 * período anterior) y devuelve SOLO datos CRUDOS — el front formatea TODO (números, fechas,
 * %, textos) y arma el markup. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');
$view = $_GET['view'] ?? 'kpis';

/* ───────────── helpers de cálculo de fechas (puros, sin BD, NO presentación) ─────────────
 * Reimplementados acá porque el BFF no carga functions.php. Son cálculos (período anterior,
 * relleno de calendario), no formateo de display. */

/** Período inmediatamente anterior, misma duración. (= getPreviousPeriod del panel) */
function bffPrevPeriod($start, $end)
{
    $s    = strtotime($start);
    $e    = strtotime($end);
    $diff = ($e - $s) + 1;
    return [date('Y-m-d H:i:00', $s - $diff), date('Y-m-d H:i:00', $e - $diff)];
}

/** Lista de fechas 'Y-m-d' entre dos extremos, paso 1 día. (= dateRange del panel) */
function bffDateRange($first, $last)
{
    $dates = [];
    $cur   = strtotime($first);
    $end   = strtotime($last);
    while ($cur <= $end) {
        $dates[] = date('Y-m-d', $cur);
        $cur     = strtotime('+1 day', $cur);
    }
    return $dates;
}

/** Día de la semana ISO (1=Lun … 7=Dom). */
function bffWeekday($date)
{
    return (int) date('N', strtotime($date));
}

/* ───────────────────────── trae datasets crudos de la API ───────────────────────── */

function bffFetchSummary($from, $to)
{
    return bffApiGet('v1/reports/sales.php', ['dataset' => 'summary', 'from' => $from, 'to' => $to], '_jwt_panel', ['base' => 'shared']);
}
function bffFetchSeries($from, $to)
{
    return bffApiGet('v1/reports/sales.php', ['dataset' => 'series', 'from' => $from, 'to' => $to], '_jwt_panel', ['base' => 'shared']);
}

/* ───────────────────────── KPIs (default) ───────────────────────── */

/** netSales = (bruto - descuentos - devoluciones) - pagos que no suman a ventas. */
function bffNetSales(array $d)
{
    $gross    = $d['totals']['total'] ?? 0;
    $discount = $d['totals']['discount'] ?? 0;
    $returns  = abs($d['returns']['total'] ?? 0);
    $nonAdd   = $d['nonAddingToSales']['total'] ?? 0;
    return (($gross - $discount) - $returns) - $nonAdd;
}

/** KPIs en NÚMEROS crudos + cálculos del BFF (paymentsTotal, totalBruto). El front formatea. */
function bffKpisRaw(array $d)
{
    $payTotal = 0;
    foreach (($d['payments'] ?? []) as $p) {
        $payTotal += (float) ($p['price'] ?? 0);
    }

    return [
        'grossSales'        => $d['totals']['total'] ?? 0,
        'totalDiscounts'    => $d['totals']['discount'] ?? 0,
        'totalReturns'      => abs($d['returns']['total'] ?? 0),
        'totalTax'          => max(0, $d['totals']['tax'] ?? 0),
        'netSales'          => bffNetSales($d),
        'cashSales'         => ($d['byType']['cash']['total'] ?? 0)   - ($d['byType']['cash']['discount'] ?? 0),
        'creditSales'       => ($d['byType']['credit']['total'] ?? 0) - ($d['byType']['credit']['discount'] ?? 0),
        'giftcardsSold'     => $d['giftcards']['total'] ?? 0,
        'giftcardsCount'    => $d['giftcards']['count'] ?? 0,
        'totalGiftcardUsed' => $d['nonAddingToSales']['totalGiftCards'] ?? 0,
        'creditPays'        => $d['nonAddingToSales']['total'] ?? 0,
        'paymentsTotal'     => $payTotal,                                            // cálculo del BFF
        'totalBruto'        => (($d['byType']['cash']['total'] ?? 0)   - ($d['byType']['cash']['discount'] ?? 0))
                             + (($d['byType']['credit']['total'] ?? 0) - ($d['byType']['credit']['discount'] ?? 0)),
        // type + name (resueltos por la API) + price crudo; el front formatea el monto.
        'payments'          => array_map(
            fn($p) => ['type' => $p['type'] ?? '', 'name' => $p['name'] ?? '', 'price' => $p['price'] ?? 0],
            $d['payments'] ?? []
        ),
    ];
}

/**
 * Comparación actual vs anterior — DATOS (cálculo del BFF), no presentación:
 * dirección, % (número), valor anterior CRUDO, y si es señal positiva. El front arma
 * el span (icono/clase) y formatea el prev. (= comparePeriodsArrowsPercent del panel)
 */
function bffCompare($now, $past, $inverted = false)
{
    $now  = abs($now ?? 0);
    $past = abs($past ?? 0);

    if ($now > $past) {
        $dir      = 'up';
        $pct      = $now ? round(($now - $past) * 100 / $now) : 0;
        $positive = !$inverted;
    } elseif ($now < $past) {
        $dir      = 'down';
        $pct      = $past ? round(($past - $now) * 100 / $past) : 0;
        $positive = (bool) $inverted;
    } else {
        $dir      = 'flat';
        $pct      = 0;
        $positive = null; // neutral
    }

    return ['dir' => $dir, 'pct' => $pct, 'prev' => $past, 'positive' => $positive]; // prev CRUDO
}

function bffViewKpis($from, $to)
{
    $cur = bffFetchSummary($from, $to);
    if (!$cur['ok']) {
        bffFailFromApi($cur);
    }

    [$pf, $pt] = bffPrevPeriod($from, $to);
    $prev      = bffFetchSummary($pf, $pt);

    $curRaw  = bffKpisRaw($cur['data']);
    $prevRaw = $prev['ok'] ? bffKpisRaw($prev['data']) : null;

    // Comparaciones (returns/discounts: subir es señal negativa → inverted).
    $compare = null;
    if ($prevRaw) {
        $compare = [
            'grossSales'     => bffCompare($curRaw['grossSales'],     $prevRaw['grossSales']),
            'totalReturns'   => bffCompare($curRaw['totalReturns'],   $prevRaw['totalReturns'],   true),
            'totalDiscounts' => bffCompare($curRaw['totalDiscounts'], $prevRaw['totalDiscounts'], true),
            'netSales'       => bffCompare($curRaw['netSales'],       $prevRaw['netSales']),
        ];
    }

    bffJson([
        'ok'   => true,
        'data' => [
            'period'  => ['from' => $from, 'to' => $to],
            'current' => $curRaw,   // números CRUDOS — el front formatea
            'compare' => $compare,
        ],
    ]);
}

/* ───────────────────────── chart (gráfico de ingresos) ─────────────────────────
 * Compone las series (cálculo: byweek, margin, alineación período anterior) y devuelve
 * NÚMEROS + FECHAS ISO crudas + promedio crudo. El front arma labels/anotación/markup. */

function bffViewChart($from, $to)
{
    $cur = bffFetchSeries($from, $to);
    if (!$cur['ok']) {
        bffFailFromApi($cur);
    }

    [$pf, $pt] = bffPrevPeriod($from, $to);
    $prev      = bffFetchSeries($pf, $pt);

    $isDay = !empty($cur['data']['isDay']);

    $byBucket = function ($rows) {
        $out = [];
        foreach ($rows as $r) {
            $out[$r['bucket']] = $r;
        }
        return $out;
    };
    $sales = $byBucket($cur['data']['sales'] ?? []);
    $exps  = $byBucket($cur['data']['expenses'] ?? []);
    $back  = $prev['ok'] ? $byBucket($prev['data']['sales'] ?? []) : [];

    $buckets  = [];
    $bucketsB = [];
    $gross    = [];
    $grossB   = [];
    $grossE   = [];
    $margin   = [];
    $byweek   = array_fill(1, 7, 0); // 1=Lun … 7=Dom

    $totalSold  = 0;
    $totalCount = 0;

    $calendar  = $isDay ? range(0, 23) : bffDateRange($from, $to);
    $calendarB = $isDay ? range(0, 23) : bffDateRange($pf, $pt);

    foreach ($calendar as $z => $current) {
        $currentB = $isDay ? $current : ($calendarB[$z] ?? null);

        $discount = $sales[$current]['discount'] ?? 0;
        $total    = $sales[$current]['total'] ?? 0;
        $totalExp = $exps[$current]['total'] ?? 0;
        $totalBak = ($currentB !== null && isset($back[$currentB]))
            ? ($back[$currentB]['total'] - $back[$currentB]['discount'])
            : 0;

        $dayTotal    = $total - $discount;
        $totalSold  += $dayTotal;
        $totalCount++;

        $buckets[]  = $current;                   // fecha ISO (multi) u hora int (single) — CRUDO
        $bucketsB[] = $currentB;
        $gross[]    = $dayTotal;
        $grossB[]   = $totalBak;
        $grossE[]   = $totalExp;
        $tMargin    = $dayTotal - $totalExp;
        $margin[]   = ($tMargin > 0) ? $tMargin : 0;

        if (!$isDay) {
            $byweek[bffWeekday($current)] += $dayTotal;
        }
    }

    // Barras "Día de la semana" (solo multi-día): 7 totales Lun→Dom (labels los pone el front).
    $daysData = [];
    if (!$isDay) {
        for ($i = 1; $i <= 7; $i++) {
            $daysData[] = $byweek[$i];
        }
    }

    $average = ($totalSold && $totalCount) ? ($totalSold / $totalCount) : null;

    bffJson([
        'ok'   => true,
        'data' => [
            'chart' => [
                'isDay'       => $isDay,
                'buckets'     => $buckets,         // fechas ISO / horas — CRUDO
                'bucketsB'    => $bucketsB,
                'periodFrom'  => $from,            // para el label single-day
                'periodFromB' => $pf,
                'gross'       => $gross,
                'grossB'      => $grossB,
                'grossE'      => $grossE,
                'margin'      => $margin,
                'daysData'    => $daysData,        // 7 valores (multi) — labels en el front
                'average'     => $average,         // promedio CRUDO — el front arma "Promedio X"
                'noDayShow'   => $isDay ? 'hidden' : '',
            ],
        ],
    ]);
}

/* ───────────────────────── hours (ventas por hora) ───────────────────────── */

function bffViewHours($from, $to)
{
    $res = bffApiGet('v1/reports/sales.php', ['dataset' => 'hours', 'from' => $from, 'to' => $to], '_jwt_panel', ['base' => 'shared']);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }

    // Rellenar las 24 horas (0 donde no hubo ventas). Devolvemos horas crudas (0-23) +
    // totales; el front arma las labels "00 h".."23 h".
    $byHour = [];
    foreach ($res['data'] as $r) {
        $byHour[(int) $r['bucket']] = (float) $r['total'];
    }

    $hours  = [];
    $totals = [];
    for ($h = 0; $h <= 23; $h++) {
        $hours[]  = $h;
        $totals[] = $byHour[$h] ?? 0;
    }

    bffJson(['ok' => true, 'data' => ['hours' => $hours, 'totals' => $totals]]);
}

/* ───────────────────────── byday (pestaña Por Día) ───────────────────────── */

function bffViewByDay($from, $to)
{
    $res = bffApiGet('v1/reports/sales.php', ['dataset' => 'byday', 'from' => $from, 'to' => $to], '_jwt_panel', ['base' => 'shared']);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }

    // Derivados por fila (cálculo del BFF) en NÚMEROS crudos. El front formatea y arma la tabla.
    $rows = [];
    foreach ($res['data'] as $r) {
        $total    = ($r['total'] < 1) ? 0 : $r['total'];
        $total    = ($total < 0) ? $total : ($total - $r['discount']);
        $subtotal = $total - $r['tax'];
        $subtotal = ($subtotal < 0) ? 0 : $subtotal;
        $tax      = ($r['tax'] < 0) ? 0 : $r['tax'];

        $rows[] = [
            'date'     => $r['date'],          // fecha ISO cruda
            'count'    => $r['count'],
            'discount' => $r['discount'],
            'tax'      => $tax,
            'subtotal' => $subtotal,
            'total'    => $total,
        ];
    }

    bffJson(['ok' => true, 'data' => ['rows' => $rows]]);
}

/* ───────────────────────── dispatch ───────────────────────── */

switch ($view) {
    case 'chart':
        bffViewChart($from, $to);
        break;
    case 'hours':
        bffViewHours($from, $to);
        break;
    case 'byday':
        bffViewByDay($from, $to);
        break;
    case 'kpis':
    default:
        bffViewKpis($from, $to);
}
