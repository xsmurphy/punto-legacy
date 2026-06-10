<?php
/**
 * BFF — Dashboard del panel.
 *
 *   GET /bff/reports/dashboard.php?widget=<nombre>&from=&to=[&week&prev&type]
 *
 * Gateway fino sobre la API (NO toca BD). El service ya computa cada widget, así que el BFF sólo
 * reenvía los datos crudos al front, que formatea + arma cada widget. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$query = array_filter([
    'widget' => $_GET['widget'] ?? '',
    'from'   => $_GET['from']   ?? '',
    'to'     => $_GET['to']     ?? '',
    'week'   => $_GET['week']   ?? '',
    'prev'   => $_GET['prev']   ?? '',
    'type'   => $_GET['type']   ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/dashboard.php', $query, '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data']]);
