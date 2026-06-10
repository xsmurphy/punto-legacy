<?php
/**
 * BFF — Reporte de Pagos y Transacciones.
 *
 *   GET /bff/reports/transactions.php?view=detail|cobros|quotes&from=&to=[&cusId&src&singleRow]
 *
 * Gateway fino sobre la API (NO toca BD). El service ya resuelve nombres/medios de pago/deuda/
 * comprobante, así que el BFF sólo reenvía los rows crudos al front, que formatea + arma las tablas.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * La vista `feTable` (API externa de Facturación Electrónica), el CRUD de edición y los fiscales
 * NO pasan por acá: el front los pide al PHP legacy vía /a_report_transactions?action=.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$view  = $_GET['view'] ?? 'detail';
$query = array_filter([
    'view'      => $view,
    'from'      => $_GET['from']      ?? '',
    'to'        => $_GET['to']        ?? '',
    'cusId'     => $_GET['cusId']     ?? '',
    'src'       => $_GET['src']       ?? '',
    'singleRow' => $_GET['singleRow'] ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/transactions.php', $query, '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data'] ?? ['rows' => []]]);
