<?php
/**
 * BFF — Reporte de Agendamientos.
 *
 *   GET  /bff/reports/schedule.php?view=detail|stats|sessions&from=&to=[&ui&uit]
 *   POST /bff/reports/schedule.php (action=delete&id=<uuid>) → elimina la cita.
 *
 * Gateway sobre la API (NO toca BD). Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 * El modal de sesiones (`detail` por id) sigue en el panel legacy vía /a_report_schedule?action=.
 */

require_once __DIR__ . '/../lib/api_client.php';

const SCHEDULE_API = ['base' => 'shared'];

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ───────── write: eliminar cita ───────── */
if ($method === 'POST') {
    $payload = [
        'action' => $_POST['action'] ?? '',
        'id'     => $_POST['id']     ?? '',
    ];
    $res = bffApiPost('v1/reports/schedule.php', $payload, '_jwt_panel', SCHEDULE_API);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

/* ───────── lectura ───────── */
$query = array_filter([
    'view' => $_GET['view'] ?? 'detail',
    'from' => $_GET['from'] ?? '',
    'to'   => $_GET['to']   ?? '',
    'ui'   => $_GET['ui']   ?? '',
    'uit'  => $_GET['uit']  ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/schedule.php', $query, '_jwt_panel', SCHEDULE_API);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data'] ?? ['rows' => []]]);
