<?php
/**
 * BFF — Cierres de Caja / Drawers.
 *
 *   GET  /bff/reports/drawers.php?from=&to=                          → { rows } CRUDO (lista).
 *   GET  /bff/reports/drawers.php?id=<uuid>                          → { detail } CRUDO (una caja).
 *   POST /bff/reports/drawers.php (action=close|correct|delete&id=…) → muta (vía la API).
 *
 * Gateway sobre la API (NO toca BD). Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ───────────── escritura: cerrar / corregir / eliminar ───────────── */
if ($method === 'POST') {
    $payload = [
        'action'      => $_POST['action'] ?? '',
        'id'          => $_POST['id'] ?? '',
        'openDate'    => $_POST['openDate'] ?? '',
        'closeDate'   => $_POST['closeDate'] ?? '',
        'openAmount'  => $_POST['openAmount'] ?? '',
        'closeAmount' => $_POST['closeAmount'] ?? '',
    ];
    $res = bffApiPost('v1/reports/drawers.php', $payload);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

/* ───────────── lectura: detalle por id ───────────── */
if (!empty($_GET['id'])) {
    $res = bffApiGet('v1/reports/drawers.php', ['id' => $_GET['id']]);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

/* ───────────── lectura: lista por período ───────────── */
$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/drawers.php', ['from' => $from, 'to' => $to]);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data']]);
