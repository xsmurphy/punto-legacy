<?php
/**
 * /bff/sales.php — BFF de guardado de ventas del POS (slice 35).
 *
 * Strangler-fig de `action.php?action=processData` para los paths cubiertos
 * por SaleService (sub-slice 35a = cashsale/creditsale simple).
 *
 * El front detecta si una venta es elegible (en `saveSale`) y la encola con
 * `endpoint:'sales'` para que `ncmHttp.stync()` la rute acá en lugar del
 * legacy. Mantiene la idempotencia por UID y devuelve el shape original
 * `{success:"true"}` (más campos opcionales `transactionId`/`uid`).
 *
 * NO toca BD: reenvía a `/api/v1/sales.php` con cookie `_jwt`.
 */

require_once __DIR__ . '/lib/bff_init.php';
// `?l=` declara la acción (espeja el contrato del legacy). El payload va en POST data[].

if ($action !== 'save' && $action !== 'processData') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

// Reenviamos `data[]` tal cual lo manda el front (jQuery $.ajax con `{data:[json]}`).
$rawData = $_POST['data'] ?? null;
if (is_array($rawData)) {
    $rawData = $rawData[0] ?? null;
}
if (!is_string($rawData) || $rawData === '') {
    bffJson(['success' => 'false', 'error' => 'falta data[]'], 400);
}

$res = bffApiPost('v1/sales.php', ['data' => [$rawData]], '_jwt');

if (!$res['ok']) {
    // Espejamos el shape legacy: {success:"true"} en éxito → en error, mantenemos
    // un shape que el front pueda interpretar (ncmHelpers.ifIstrue chequea "true").
    //
    // Propagación de status: 4xx (payload inválido, conflict, not-found) DEBE llegar
    // al front como 4xx para que la cola offline NO reintente — si caemos a 502, el
    // ncmHttp.stync loopearía la venta inválida indefinidamente. Solo 5xx queda como
    // 502 (API caída / error real). Mantiene 401/403 igual.
    $err    = $res['error'] ?: 'error en la API';
    $status = $res['status'];
    if ($status >= 400 && $status < 500) {
        $passthrough = $status; // 401/403/404/409/422/etc — no reintentar
    } else {
        $passthrough = 502;     // 5xx upstream → genuino fallo de API
    }
    bffJson(['success' => 'false', 'error' => $err], $passthrough);
}

// Éxito: shape original + extras útiles (transactionId/uid para reconciliación futura).
$out = ['success' => 'true'];
if (!empty($res['data']['transactionId'])) {
    $out['transactionId'] = $res['data']['transactionId'];
}
if (!empty($res['data']['uid'])) {
    $out['uid'] = $res['data']['uid'];
}
if (!empty($res['data']['duplicated'])) {
    $out['duplicated'] = true;
}

bffJson($out);
