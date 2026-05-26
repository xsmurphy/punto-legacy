<?php
/**
 * BFF — Reporte Resumen de Ventas.
 *
 *   GET /bff/reports/summary.php?from=<datetime>&to=<datetime>&view=<vista>
 *
 *   view (default 'kpis'):
 *     kpis  → KPIs del período actual + anterior (tarjetas + tablas Ventas/Medios/Tipos/GiftCards).
 *     chart → datos del gráfico de ingresos (series actual + anterior, egresos, margen,
 *             barras por día de la semana, anotación de promedio). Shape que espera drawChart().
 *     hours → ventas por hora del día (shape que espera chartByHours()).
 *     byday → filas crudas por día (la pestaña "Por Día"; el front arma la tabla).
 *
 * Composición sobre la API (NO toca BD): llama GET /API/v1/reports/sales?dataset=… por
 * período y compone los derivados (netSales, margin, byweek, alineación período anterior).
 * Los NÚMEROS van crudos — el front los formatea. Excepción documentada: las labels del eje
 * del gráfico y el texto de la anotación se arman acá (es "formateo" de composición, y
 * mantiene drawChart() intacto = diseño idéntico). Ver context/02-arquitectura.md § BFF.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');
$view = $_GET['view'] ?? 'kpis';

/* ───────────────────────── helpers de fecha (puros, sin BD) ─────────────────────────
 * Reimplementados acá porque el BFF no carga functions.php. Espejo fiel de los del panel. */

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

/** Fecha "linda" literal: "Lun 26, May 2026". (= niceDate(...,literal=true) del panel) */
function bffNiceDate($date)
{
    static $dias  = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    static $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return 'No date';
    }
    $t = strtotime($date);
    $w = (int) date('w', $t);
    $d = date('d', $t);
    $m = (int) date('m', $t);
    $y = date('Y', $t);
    return $dias[$w] . ' ' . $d . ', ' . $meses[$m - 1] . ' ' . $y;
}

/* ───────────────────────── trae datasets crudos de la API ───────────────────────── */

function bffFetchSummary($from, $to)
{
    return bffApiGet('v1/reports/sales.php', ['dataset' => 'summary', 'from' => $from, 'to' => $to]);
}
function bffFetchSeries($from, $to)
{
    return bffApiGet('v1/reports/sales.php', ['dataset' => 'series', 'from' => $from, 'to' => $to]);
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

/** Aplana el dataset crudo de la API a los KPIs que el front pinta (números crudos). */
function bffKpis(array $d)
{
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
        // type + name (resueltos por la API) + price crudo; el front formatea el monto.
        'payments'          => array_map(
            fn($p) => ['type' => $p['type'] ?? '', 'name' => $p['name'] ?? '', 'price' => $p['price'] ?? 0],
            $d['payments'] ?? []
        ),
    ];
}

function bffViewKpis($from, $to)
{
    $cur = bffFetchSummary($from, $to);
    if (!$cur['ok']) {
        bffFailFromApi($cur);
    }

    [$pf, $pt] = bffPrevPeriod($from, $to);
    $prev      = bffFetchSummary($pf, $pt);

    bffJson([
        'ok'   => true,
        'data' => [
            'period'   => ['from' => $from, 'to' => $to],
            'current'  => bffKpis($cur['data']),
            'previous' => $prev['ok'] ? bffKpis($prev['data']) : null,
        ],
    ]);
}

/* ───────────────────────── chart (gráfico de ingresos) ─────────────────────────
 * Port fiel de la composición que vivía en a_report_summary.php (action=getChartSales). */

function bffViewChart($from, $to)
{
    $cur = bffFetchSeries($from, $to);
    if (!$cur['ok']) {
        bffFailFromApi($cur);
    }

    [$pf, $pt] = bffPrevPeriod($from, $to);
    $prev      = bffFetchSeries($pf, $pt);

    $isDay = !empty($cur['data']['isDay']);

    // Indexar filas crudas por bucket (fecha 'Y-m-d' multi-día / hora int single-day).
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

    $labels = [];
    $gross  = [];
    $grossB = [];
    $grossE = [];
    $margin = [];
    $byweek = array_fill(1, 7, 0); // 1=Lun … 7=Dom

    $totalSold  = 0;
    $totalCount = 0;

    if ($isDay) {
        $calendar  = range(0, 23);
        $calendarB = $calendar;
        $startB    = $pf;
    } else {
        $calendar  = bffDateRange($from, $to);
        $calendarB = bffDateRange($pf, $pt);
    }

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

        $gross[]  = $dayTotal;
        $grossB[] = $totalBak;
        $grossE[] = $totalExp;
        $tMargin  = $dayTotal - $totalExp;
        $margin[] = ($tMargin > 0) ? $tMargin : 0;

        if ($isDay) {
            $labels[] = $current . 'h del ' . bffNiceDate($from) . ' vs ' . bffNiceDate($startB);
        } else {
            $byweek[bffWeekday($current)] += $dayTotal;
            $labels[]                      = bffNiceDate($current) . ' vs ' . bffNiceDate($currentB);
        }
    }

    // Barras "Día de la semana" (solo multi-día). Lun→Dom.
    $dayNames  = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    $daysLabel = [];
    $daysData  = [];
    if (!$isDay) {
        for ($i = 1; $i <= 7; $i++) {
            $daysLabel[] = $dayNames[$i - 1];
            $daysData[]  = $byweek[$i];
        }
    }

    // Anotación de promedio. El texto se arma acá (mantiene drawChart intacto); el número
    // se formatea con el config de la company (vía bootstrap de la API).
    $annotations = [];
    if ($totalSold && $totalCount) {
        $average       = $totalSold / $totalCount;
        $annotations[] = [
            'value'       => $average,
            'orientation' => 'horizontal',
            'text'        => 'Promedio ' . bffFormatNumber($average),
            'color'       => '#1ab667',
            'position'    => 'left',
        ];
    }

    bffJson([
        'ok'   => true,
        'data' => [
            'chart' => [
                'sales'       => [
                    'labels' => $labels,
                    'gross'  => $gross,
                    'grossB' => $grossB,
                    'grossE' => $grossE,
                    'margin' => $margin,
                ],
                'days'        => ['labels' => $daysLabel, 'data' => $daysData],
                'annotations' => $annotations,
                'noDayShow'   => $isDay ? 'hidden' : '',
            ],
        ],
    ]);
}

/* ───────────────────────── hours (ventas por hora) ───────────────────────── */

function bffViewHours($from, $to)
{
    $res = bffApiGet('v1/reports/sales.php', ['dataset' => 'hours', 'from' => $from, 'to' => $to]);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }

    // Rellenar las 24 horas (0 donde no hubo ventas). Labels '00 h'..'23 h' (presentación
    // simple, se arma acá para que chartByHours quede intacto).
    $byHour = [];
    foreach ($res['data'] as $r) {
        $byHour[(int) $r['bucket']] = (float) $r['total'];
    }

    $hour  = [];
    $total = [];
    for ($h = 0; $h <= 23; $h++) {
        $hour[]  = ($h < 10 ? '0' . $h : (string) $h) . ' h';
        $total[] = $byHour[$h] ?? 0;
    }

    bffJson(['ok' => true, 'data' => ['hour' => $hour, 'total' => $total]]);
}

/* ───────────────────────── byday (pestaña Por Día) ───────────────────────── */

function bffViewByDay($from, $to)
{
    $res = bffApiGet('v1/reports/sales.php', ['dataset' => 'byday', 'from' => $from, 'to' => $to]);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }

    // Derivados por fila (números crudos; el front formatea y arma la tabla).
    $rows = [];
    foreach ($res['data'] as $r) {
        $total    = ($r['total'] < 1) ? 0 : $r['total'];
        $total    = ($total < 0) ? $total : ($total - $r['discount']);
        $subtotal = $total - $r['tax'];
        $subtotal = ($subtotal < 0) ? 0 : $subtotal;
        $tax      = ($r['tax'] < 0) ? 0 : $r['tax'];

        $rows[] = [
            'date'     => $r['date'],
            'count'    => $r['count'],
            'discount' => $r['discount'],
            'tax'      => $tax,
            'subtotal' => $subtotal,
            'total'    => $total,
        ];
    }

    bffJson(['ok' => true, 'data' => ['rows' => $rows]]);
}

/* ───────────────────────── formateo del config de la company ─────────────────────────
 * Solo para el texto de la anotación del gráfico. El BFF pide el config a la API (bootstrap),
 * que es la única capa con BD. (= formatCurrentNumber del panel) */

function bffFormatNumber($number)
{
    static $cfg = null;
    if ($cfg === null) {
        $b   = bffApiGet('v1/bootstrap.php');
        $cfg = ($b['ok'] && is_array($b['data'])) ? $b['data'] : [];
    }
    $decimal  = $cfg['decimal']  ?? 'no';
    $thousand = $cfg['thousand'] ?? 'dot';

    if (!is_numeric($number)) {
        $number = 0;
    }
    if ($decimal === 'no') {
        $number = round($number);
        return ($thousand === 'comma')
            ? number_format($number, 0, '.', ',')
            : number_format($number, 0, ',', '.');
    }
    return ($thousand === 'comma')
        ? number_format($number, 2, '.', ',')
        : number_format($number, 2, ',', '.');
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
