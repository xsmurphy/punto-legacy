<?php
/**
 * BFF — Reporte de Satisfacción (NPS).
 *
 *   GET  /bff/reports/satisfaction.php?from=&to=   → { rows, nps } CRUDO (el front formatea).
 *   POST /bff/reports/satisfaction.php (action=delete&id=<uuid>) → borra un voto (vía la API).
 *
 * Gateway sobre la API (NO toca BD) + cálculo de los conteos/% NPS (detractores/pasivos/
 * promotores). Primer reporte con ESCRITURA por el BFF. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ───────────── escritura: borrar un voto ───────────── */
if ($method === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id  = $_POST['id'] ?? '';
    $res = bffApiPost('v1/reports/satisfaction.php', ['action' => 'delete', 'id' => $id], '_jwt_panel', ['base' => 'shared']);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

/* ───────────── lectura: votos + NPS ───────────── */
$from = $_GET['from'] ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
$to   = $_GET['to']   ?? date('Y-m-d 23:59:59');

$res = bffApiGet('v1/reports/satisfaction.php', ['from' => $from, 'to' => $to], '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

$rows = $res['data'] ?? [];

// NPS (cálculo del BFF): nivel 1 = detractor (malo), 2 = pasivo (bueno), resto = promotor (excelente).
$bad = 0; $med = 0; $good = 0;
foreach ($rows as $r) {
    $lvl = (int) ($r['level'] ?? 0);
    if ($lvl === 1)      { $bad++; }
    elseif ($lvl === 2)  { $med++; }
    else                 { $good++; }
}
$total = $bad + $med + $good;
$pct   = fn($n) => $total ? (int) round($n * 100 / $total) : 0;

bffJson(['ok' => true, 'data' => [
    'rows' => $rows,
    'nps'  => [
        'bad'   => $bad,  'badPercent'  => $pct($bad),
        'med'   => $med,  'medPercent'  => $pct($med),
        'good'  => $good, 'goodPercent' => $pct($good),
    ],
]]);
