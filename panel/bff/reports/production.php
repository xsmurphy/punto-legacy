<?php
/**
 * BFF — Reporte de Producción.
 *
 *   GET /bff/reports/production.php?view=general|detail|compound&from=&to=[&byDay=1]
 *
 * Gateway fino sobre la API (NO toca BD). El service ya resuelve nombres/utilidad/totales, así que
 * el BFF sólo reenvía los datos crudos (rows + totals) al front, que formatea + arma tablas/KPIs.
 * Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * El modal de receta (`recipe`), el export y el write (`delete`) NO pasan por acá: el front los
 * pide al PHP legacy vía /a_report_production?action=.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$query = array_filter([
    'view'  => $_GET['view']  ?? 'general',
    'from'  => $_GET['from']  ?? '',
    'to'    => $_GET['to']    ?? '',
    'byDay' => $_GET['byDay'] ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/production.php', $query, '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data'] ?? ['rows' => []]]);
