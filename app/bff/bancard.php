<?php
/**
 * /bff/bancard.php — BFF de QR Bancard del POS (Slice 43).
 *
 * Strangler de app/load.php?load=bancardQR. Reenvía a /api/v1/bancard.php
 * (cookie _jwt) y devuelve la respuesta cruda de Bancard al front.
 *
 * El front (ncmPayments.ePOS.create/refresh/cancel) llama con:
 *   POST — load:bancardQR, type:create|refresh|cancel + params
 * Mapeamos `load` a `action` por compatibilidad con bff_init.php.
 *
 * PATH DE DINERO — ante cualquier fallo devolver JSON de error, nunca
 * indicar éxito falsamente.
 */

require_once __DIR__ . '/lib/bff_init.php';

// El front pasa `load:'bancardQR'`, bff_init lee `action`. Mapear.
$effectiveAction = $action !== '' ? $action : (string) ($get['load'] ?? '');

if ($effectiveAction !== 'bancardQR') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$type   = (string) ($get['type'] ?? '');
$ep     = 'v1/bancard.php';
$params = ['type' => $type];

if ($type === 'create') {
    $params['QRAmount']   = (string) ($get['QRAmount']   ?? '');
    $params['saleAmount'] = (string) ($get['saleAmount'] ?? '');
    $params['UID']        = (string) ($get['UID']        ?? '');
    if (!empty($get['comission'])) { $params['comission'] = (string) $get['comission']; }
    if (!empty($get['tax']))       { $params['tax']       = (string) $get['tax']; }
} elseif ($type === 'refresh' || $type === 'cancel') {
    $params['id'] = (string) ($get['ID'] ?? '');
} else {
    bffJson(['error' => 'type inválido'], 400);
}

$res = bffApiPost($ep, $params, '_jwt');

if (!$res['ok']) {
    bffJson(['error' => 'bancard_error']);
}

// El front parsea la respuesta de Bancard directamente (qr_data, id, qr_url, etc.).
// El endpoint envuelve en apiOk → $res['data'] es el objeto Bancard.
bffJson(is_array($res['data']) ? $res['data'] : []);
