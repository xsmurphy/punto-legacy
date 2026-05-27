<?php
/**
 * BFF — Reporte de Ventas por Medios de Pago.
 *
 *   GET /bff/reports/payment-methods.php?from=<datetime>&to=<datetime>
 *       → { detail: [...], summary: [...] } con montos PRE-FORMATEADOS (string de display)
 *         + crudos (*Raw, para sort/footer de DataTables y el chart). El front arma las
 *         tablas (Detallado + Resumido) y el chart. Cero HTML acá.
 *
 * Composición sobre la API (NO toca BD): llama GET /API/v1/reports/payment-methods,
 * pre-formatea. Ver context/02-arquitectura.md § REGLA RAÍZ 2 / BFF de 3 niveles.
 */

require_once __DIR__ . '/../lib/api_client.php';
require_once __DIR__ . '/../lib/format.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/payment-methods.php', ['from' => $from, 'to' => $to]);
if (!$res['ok']) {
    bffFailFromApi($res);
}

$d = $res['data'];

// Detalle: montos pre-formateados (display) + crudos (*Raw para data-order/sort/footer).
$detail = array_map(function ($r) {
    return [
        'transactionId' => $r['transactionId'] ?? '',
        'invoiceNo'     => $r['invoiceNo'] ?? '',
        'customerName'  => $r['customerName'] ?? '',
        'customerTin'   => $r['customerTin'] ?? '-',
        'methodType'    => $r['methodType'] ?? '',
        'methodName'    => $r['methodName'] ?? '',
        'extra'         => $r['extra'] ?? '',
        'outletName'    => $r['outletName'] ?? '',
        'price'         => bffFormatNumber($r['price'] ?? 0),
        'priceRaw'      => $r['price'] ?? 0,
        'total'         => bffFormatNumber($r['total'] ?? 0),
        'totalRaw'      => $r['total'] ?? 0,
        'txnTotal'      => bffFormatNumber($r['txnTotal'] ?? 0),
        'txnTotalRaw'   => $r['txnTotal'] ?? 0,
    ];
}, $d['detail'] ?? []);

// Resumen por medio: nombre + monto formateado + crudo (para sort/footer y el chart).
$summary = array_map(function ($g) {
    return [
        'type'     => $g['type'] ?? '',
        'name'     => $g['name'] ?? '',
        'price'    => bffFormatNumber($g['price'] ?? 0),
        'priceRaw' => $g['price'] ?? 0,
    ];
}, $d['summary'] ?? []);

bffJson(['ok' => true, 'data' => ['detail' => $detail, 'summary' => $summary]]);
