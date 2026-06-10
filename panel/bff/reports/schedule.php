<?php
/**
 * BFF — Reporte de Agendamientos.
 *
 *   GET /bff/reports/schedule.php?view=detail|stats|sessions&from=&to=[&ui&uit]
 *
 * Gateway fino sobre la API (NO toca BD). El service ya resuelve nombres/comprobantes/conteos,
 * así que el BFF sólo reenvía los datos crudos (rows + summary en detail) al front, que formatea
 * + arma tablas/KPIs/donut. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * El modal de sesiones (`detail` por id) y el write (`delete`) NO pasan por acá: el front los pide
 * al PHP legacy vía /a_report_schedule?action=.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$query = array_filter([
    'view' => $_GET['view'] ?? 'detail',
    'from' => $_GET['from'] ?? '',
    'to'   => $_GET['to']   ?? '',
    'ui'   => $_GET['ui']   ?? '',
    'uit'  => $_GET['uit']  ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/schedule.php', $query, '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data'] ?? ['rows' => []]]);
