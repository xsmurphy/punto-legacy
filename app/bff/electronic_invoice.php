<?php
/**
 * /bff/electronic_invoice.php — BFF de facturación electrónica del POS.
 *
 * Reemplaza consultStatusElectronicInvoice (action.php L3558). El front manda la acción
 * en `?l=` y el payload `data` (rango de fechas/documento) en el POST body. NO toca BD:
 * reenvía a /api/v1/electronic_invoice.php (cookie _jwt) y devuelve el documento de FE al
 * top level (el front hace JSON.parse y lee .cdc/.docNro). Ante fallo → dispara el onFail
 * del front (que setea cdc="").
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];

if (($get['action'] ?? '') !== 'consultStatusElectronicInvoice') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$data = $_POST['data'] ?? [];
if (!is_array($data)) {
    $data = [];
}

$res = bffApiPost('v1/electronic_invoice.php', ['data' => $data], '_jwt');
if (!$res['ok']) {
    bffFailFromApi($res);
}

// El front espera el documento de FE crudo (lo JSON.parse). data = el documento.
bffJson($res['data']);
