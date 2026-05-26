<?php
/**
 * BFF — Reporte Resumen de Ventas.
 *
 *   GET /bff/reports/summary.php?from=<datetime>&to=<datetime>
 *       → KPIs compuestos del resumen (ventas, tipos, giftcards) + período anterior
 *         para comparación. Números CRUDOS (el front formatea; la presentación vive
 *         en el front por decisión 2026-05-26).
 *
 * Composición sobre la API (NO toca BD): llama GET /API/v1/reports/sales para el
 * período actual y el anterior, calcula netSales y los totales por tipo, y devuelve
 * todo en crudo. El cálculo cruzado/derivado es trabajo del BFF; el formateo y las
 * flechas de comparación, del front. Ver context/02-arquitectura.md § BFF de 3 niveles.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

/** Período inmediatamente anterior, misma duración (date math, sin BD). */
function bffPrevPeriod($start, $end)
{
    $s    = strtotime($start);
    $e    = strtotime($end);
    $diff = ($e - $s) + 1;
    return [date('Y-m-d H:i:00', $s - $diff), date('Y-m-d H:i:00', $e - $diff)];
}

/** Trae el dataset crudo de un período desde la API. */
function bffFetchSales($from, $to)
{
    return bffApiGet('v1/reports/sales.php', ['from' => $from, 'to' => $to]);
}

/** netSales = (bruto - descuentos - devoluciones) - pagos que no suman a ventas. */
function bffNetSales(array $d)
{
    $gross    = $d['totals']['total'] ?? 0;
    $discount = $d['totals']['discount'] ?? 0;
    $returns  = abs($d['returns']['total'] ?? 0);
    $nonAdd   = $d['nonAddingToSales']['total'] ?? 0;
    return (($gross - $discount) - $returns) - $nonAdd;
}

/**
 * Aplana el dataset crudo de la API a los KPIs que el front pinta.
 * Null-coalesce defensivo: si la API degradara a un envelope parcial, no
 * inyectamos warnings de PHP en el JSON.
 */
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
        'payments'          => array_map(
            fn($p) => ['type' => $p['type'] ?? '', 'price' => $p['price'] ?? 0],
            $d['payments'] ?? []
        ),
    ];
}

$cur = bffFetchSales($from, $to);
if (!$cur['ok']) {
    bffFailFromApi($cur);
}

[$pf, $pt] = bffPrevPeriod($from, $to);
$prev = bffFetchSales($pf, $pt);

$current  = bffKpis($cur['data']);
$previous = $prev['ok'] ? bffKpis($prev['data']) : null;

bffJson([
    'ok'   => true,
    'data' => [
        'period'   => ['from' => $from, 'to' => $to],
        'current'  => $current,
        'previous' => $previous,
    ],
]);
