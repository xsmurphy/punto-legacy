<?php
/**
 * BFF — Reporte de Historial de Stock / Inventario.
 *
 *   GET /bff/reports/inventory.php?dataset=movements|widget&from=&to=&itemId=&byDay=
 *       → datos CRUDOS de la API (números, fechas ISO, source key). El front formatea,
 *         traduce `source`, arma la tabla (modo general/byDay) + los KPIs. Cero HTML.
 *
 * Gateway sobre la API (NO toca BD): forwardea. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$dataset = $_GET['dataset'] ?? 'movements';

$query = ['dataset' => $dataset === 'widget' ? 'widget' : 'movements'];
if ($query['dataset'] === 'movements') {
    $query['from']   = $_GET['from']   ?? date('Y-m-d 00:00:00', strtotime('-7 days'));
    $query['to']     = $_GET['to']     ?? date('Y-m-d 23:59:59');
    if (!empty($_GET['itemId'])) { $query['itemId'] = $_GET['itemId']; }
    if (!empty($_GET['byDay']))  { $query['byDay']  = 1; }
}

// MIGRACIÓN PARCIAL: movements → /api compartida; widget → panel local (legacy).
// El cálculo del widget en el panel está latente roto en PG (devuelve 0,0,0 con datos
// reales); corregirlo requiere validar la semántica (sucursal vs company, fila única vs
// suma de locations) con producto. Cuando se cierre esa decisión, el widget migra y se
// elimina esta rama. Ver docs/PLAN_panel_desacople.md § Fase 2 batch 6.
$opts = $query['dataset'] === 'widget' ? [] : ['base' => 'shared'];
$res  = bffApiGet('v1/reports/inventory.php', $query, '_jwt_panel', $opts);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data']]);
