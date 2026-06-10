<?php
/**
 * BFF — Reporte de Gift Cards.
 *
 *   GET /bff/reports/giftcards.php?view=detail[&singleRow=]
 *
 * Gateway sobre la API (NO toca BD) + DERIVADOS: computa los KPIs (vencidas/por-vencer/
 * canjeadas/vigentes + valor vigente CRUDO) a partir de las filas. El front formatea + arma
 * la tabla. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * El form de edición (`giftcard`) y los writes (`update`/`delete`) NO pasan por acá:
 * el front los pide al PHP legacy vía /a_report_giftcards?action=.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$query = array_filter([
    'view'      => $_GET['view']      ?? 'detail',
    'singleRow' => $_GET['singleRow'] ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/giftcards.php', $query, '_jwt_panel', ['base' => 'shared']);
if (!$res['ok']) {
    bffFailFromApi($res);
}
$rows = $res['data']['rows'] ?? [];

// KPIs (mismo criterio que el legacy): vencidas, por-vencer (<1 semana), canjeadas (saldo 0),
// vigentes (no vencidas con saldo > 0) + valor vigente CRUDO (el front formatea).
$now    = time();
$inWeek = strtotime('+1 week');
$kpi = ['expired' => 0, 'soon' => 0, 'noCredit' => 0, 'available' => 0, 'availableValue' => 0.0];
foreach ($rows as $r) {
    $exp = $r['expires'] ? strtotime($r['expires']) : 0;
    $val = (float) ($r['value'] ?? 0);
    // Sin fecha de vencimiento ($exp = 0) cuenta como vencida, igual que el legacy
    // (strtotime('') === false → 0 < hoy). Mantiene el conteo y el valor vigente idénticos.
    if (!$exp || $exp < $now) {
        $kpi['expired']++;
    } elseif ($val > 0) {
        $kpi['available']++;
        $kpi['availableValue'] += $val;
    }
    if ($val == 0) {
        $kpi['noCredit']++;
    }
    if ($exp && $exp < $inWeek && $exp > $now) {
        $kpi['soon']++;
    }
}

bffJson(['ok' => true, 'data' => ['rows' => $rows, 'kpi' => $kpi]]);
