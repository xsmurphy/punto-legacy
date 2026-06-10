<?php
/**
 * BFF — Facturas Recurrentes.
 *
 *   GET  /bff/reports/recurring.php                                   → { rows } CRUDO (front formatea).
 *   POST /bff/reports/recurring.php (action=pause|activate|remove&id=<uuid>) → muta (vía la API).
 *
 * Gateway sobre la API (NO toca BD). Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ───────────── escritura: pausar / activar / eliminar ───────────── */
if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = $_POST['id'] ?? '';
    $res    = bffApiPost('v1/reports/recurring.php', ['action' => $action, 'id' => $id], '_jwt_panel', ['base' => 'shared']);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

/* ───────────── lectura: lista de recurrencias ───────────── */
$res = bffApiGet('v1/reports/recurring.php', [], '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data']]);
