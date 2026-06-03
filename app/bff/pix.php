<?php
/**
 * /bff/pix.php — BFF de QR Pix del POS (Slice 43).
 *
 * Strangler de app/load.php?load=pixQR y load=verifyTransactionPix.
 * Reenvía a /api/v1/pix.php (cookie _jwt).
 *
 * El front llama con:
 *   GET  — load:pixQR, type:create + params en POST body
 *   GET  — load:verifyTransactionPix, referenceId, token
 *
 * PATH DE DINERO — ante fallo devolver error, nunca reportar éxito falso.
 * ⚠️ DEUDA: token Pix viaja por el cliente. Ver PixService.php.
 */

require_once __DIR__ . '/lib/bff_init.php';

$load            = (string) ($get['load'] ?? '');
$effectiveAction = $action !== '' ? $action : $load;

if ($effectiveAction === 'pixQR') {
    $type = (string) ($get['type'] ?? '');
    if ($type !== 'create') {
        // cancel no tiene implementación en el legacy; mantener paridad.
        bffJson([]);
    }

    // pixQR create: el front manda UID en ?l= pero los datos del pago (QRAmount, name,
    // description, cpf…) vienen en el POST body (ver app.js:2836-2843).
    $params = [
        'type'        => 'create',
        'QRAmount'    => (string) ($_POST['QRAmount']    ?? ($get['QRAmount']    ?? '')),
        'description' => (string) ($_POST['description'] ?? ($get['description'] ?? '')),
        'name'        => (string) ($_POST['name']        ?? ($get['name']        ?? '')),
        'phone'       => (string) ($_POST['phone']       ?? ($get['phone']       ?? '')),
        'email'       => (string) ($_POST['email']       ?? ($get['email']       ?? '')),
        'cpf'         => (string) ($_POST['cpf']         ?? ($get['cpf']         ?? '')),
    ];

    $res = bffApiPost('v1/pix.php', $params, '_jwt');
    if (!$res['ok']) {
        bffJson(['error' => 'pix_error']);
    }
    // El front lee data.reference_id, data.qr_image, data.token, etc.
    bffJson(is_array($res['data']) ? $res['data'] : []);
}

if ($effectiveAction === 'verifyTransactionPix') {
    $params = [
        'type'        => 'verify',
        'token'       => (string) ($get['token']       ?? ''),
        'referenceId' => (string) ($get['referenceId'] ?? ''),
    ];

    $res = bffApiPost('v1/pix.php', $params, '_jwt');
    if (!$res['ok']) {
        bffJson(['error' => is_string($res['error'] ?? null) ? $res['error'] : 'pix_verify_error']);
    }
    // Front consume result.success → el endpoint devuelve {ok,data:{success:[...]}}
    // → bffJson($res['data']) emite {success:[...]} al front.
    bffJson(is_array($res['data']) ? $res['data'] : []);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
