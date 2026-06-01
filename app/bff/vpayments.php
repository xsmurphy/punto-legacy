<?php
/**
 * /bff/vpayments.php — BFF de pagos electrónicos del POS (Slice 15, cluster ENCOM→Punto).
 *
 * Reemplaza los 3 handlers de pago de action.php que hacían proxy curl a
 * panel/API/add_vpayment.php (roto en dev):
 *   ePOSAddCardTransaction        (L629) → source POS
 *   cajaPOSAddCardAndQrTransaction (L666) → source bancardPOS (+ operationData)
 *   PixAddTransaction             (L704) → source PIX (+ operationData)
 *
 * NO toca BD. Reenvía a /api/v1/vpayments.php (cookie _jwt) y devuelve el shape legacy al
 * top level (el front lee result.success / result.error). Ante CUALQUIER fallo devuelve
 * { error:'payment_failed', success:false } — NUNCA reportar éxito en un pago si falló.
 */

require_once __DIR__ . '/lib/bff_init.php';

// action.php → source de add_vpayment
$sourceMap = [
    'ePOSAddCardTransaction'         => 'POS',
    'cajaPOSAddCardAndQrTransaction' => 'bancardPOS',
    'PixAddTransaction'              => 'PIX',
];

if (!isset($sourceMap[$action])) {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$source  = $sourceMap[$action];
$payload = [
    'source'      => $source,
    'status'      => 'REVIEW',
    'authCode'    => (string) ($get['authCode'] ?? ''),
    'amount'      => (string) ($get['total'] ?? ''),
    'saleAmount'  => (string) ($get['sale'] ?? ''),
    'UID'         => (string) ($get['UID'] ?? ''),
    'operationNo' => (string) ($get['operationNo'] ?? ''),
];

// bancardPOS / PIX adjuntan operationData (json) en el campo data.
if ($source !== 'POS' && !empty($get['operationData'])) {
    $payload['data'] = is_array($get['operationData'])
        ? json_encode($get['operationData'])
        : (string) $get['operationData'];
}

$res = bffApiPost('v1/vpayments.php', $payload, '_jwt');

// Degradación segura para un path de dinero: si la API falla, NO reportar éxito.
bffJson(($res['ok'] && is_array($res['data']))
    ? $res['data']
    : ['error' => 'payment_failed', 'success' => false]);
