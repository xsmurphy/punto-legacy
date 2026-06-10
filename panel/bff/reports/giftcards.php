<?php
/**
 * BFF — Reporte de Gift Cards.
 *
 *   GET  /bff/reports/giftcards.php?view=detail[&singleRow=]
 *   POST /bff/reports/giftcards.php (action=delete|update&id=…) → proxy a la API.
 *
 * Gateway sobre la API (NO toca BD) + DERIVADOS: computa los KPIs (vencidas/por-vencer/
 * canjeadas/vigentes + valor vigente CRUDO) a partir de las filas. El front formatea + arma
 * la tabla. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../lib/api_client.php';

const GIFTCARDS_API = ['base' => 'shared'];

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ───────── write: eliminar / actualizar gift card ───────── */
if ($method === 'POST') {
    $payload = [
        'action'        => $_POST['action']        ?? '',
        'id'            => $_POST['id']            ?? '',
        'code'          => $_POST['code']          ?? '',
        'value'         => $_POST['value']         ?? '',
        'expires'       => $_POST['expires']       ?? '',
        'note'          => $_POST['note']          ?? '',
        'sendDate'      => $_POST['sendDate']      ?? '',
        'beneficiaryId' => $_POST['beneficiaryId'] ?? '',
    ];
    $res = bffApiPost('v1/reports/giftcards.php', $payload, '_jwt_panel', GIFTCARDS_API);
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

/* ───────── lectura ───────── */
$query = array_filter([
    'view'      => $_GET['view']      ?? 'detail',
    'singleRow' => $_GET['singleRow'] ?? '',
], fn($v) => $v !== '');

$res = bffApiGet('v1/reports/giftcards.php', $query, '_jwt_panel', GIFTCARDS_API);
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
