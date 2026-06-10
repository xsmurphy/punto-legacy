<?php
/**
 * BFF — Reporte de Compras y Gastos.
 *
 *   GET  /bff/reports/purchases.php?view=general|cobros|detail&from=&to=[&supId&itmId&singleRow&src]
 *   POST /bff/reports/purchases.php (action=deletePayment&id=…) → proxy a la API.
 *
 * Gateway fino sobre la API (NO toca BD). Las 3 vistas son tablas de lectura; el service ya
 * resuelve nombres/medios de pago/deuda, así que el BFF sólo reenvía los rows crudos al front,
 * que formatea + arma las tablas. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * El CRUD de edición (edit/update/paymentForm/addPayment/delete) y los fiscales (rg90,
 * libro-compra) NO pasan por acá: el front los pide al PHP legacy vía /a_report_purchases?action=.
 */

require_once __DIR__ . '/../lib/api_client.php';

const PURCHASES_API = ['base' => 'shared'];

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ───────── write: eliminar pago a proveedor ───────── */
if ($method === 'POST') {
    $res = bffApiPost('v1/reports/purchases.php', [
        'action' => $_POST['action'] ?? '',
        'id'     => $_POST['id']     ?? '',
        'parent' => $_POST['parent'] ?? '',
    ], '_jwt_panel', PURCHASES_API);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

$view  = $_GET['view'] ?? 'general';
$query = array_filter([
    'view'      => $view,
    'from'      => $_GET['from']      ?? '',
    'to'        => $_GET['to']        ?? '',
    'supId'     => $_GET['supId']     ?? '',
    'itmId'     => $_GET['itmId']     ?? '',
    'singleRow' => $_GET['singleRow'] ?? '',
    'src'       => $_GET['src']       ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/purchases.php', $query, '_jwt_panel', PURCHASES_API);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data'] ?? ['rows' => []]]);
