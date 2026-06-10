<?php
/**
 * BFF — Movimientos de Caja.
 *
 *   GET  /bff/reports/expenses.php?from=&to=                         → { rows, users } CRUDO (front formatea).
 *   POST /bff/reports/expenses.php (action=update|delete&id=<uuid> …) → muta (vía la API).
 *
 * Gateway sobre la API (NO toca BD). Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * Primer BFF del panel que consume la /api COMPARTIDA (Fase 1 del desacople): base 'shared'
 * (PUNTO_SHARED_API_BASE) en vez de la API local del panel; auth por Bearer del _jwt_panel.
 */

require_once __DIR__ . '/../lib/api_client.php';

// Consumir la /api compartida (no la API local del panel). Ver Fase 0/1 del desacople.
const EXPENSES_API = ['base' => 'shared'];

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ───────────── escritura: editar / eliminar ───────────── */
if ($method === 'POST') {
    $payload = [
        'action' => $_POST['action'] ?? '',
        'id'     => $_POST['id'] ?? '',
        'date'   => $_POST['date'] ?? '',
        'total'  => $_POST['total'] ?? '',
        'note'   => $_POST['note'] ?? '',
        'user'   => $_POST['user'] ?? '',
    ];
    $res = bffApiPost('v1/reports/expenses.php', $payload, '_jwt_panel', EXPENSES_API);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

/* ───────────── lectura: movimientos del período ───────────── */
$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/expenses.php', ['from' => $from, 'to' => $to], '_jwt_panel', EXPENSES_API);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data']]);
