<?php
/**
 * /bff/customer_note.php — BFF de notas de cliente del POS (slice 5).
 *
 * Reemplaza el dispatch de customerNote de action.php. NO toca BD: decodifica el
 * sobre `?l=` del front, reenvía a la API v1 (cookie _jwt) y devuelve {success:"true"}.
 */

require_once __DIR__ . '/lib/bff_init.php';

if (($get['action'] ?? '') !== 'customerNote') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$res = bffApiPost('v1/customer_note.php', [
    'customerId' => (string) ($get['i'] ?? ''),
    'text'       => (string) ($get['n'] ?? ''),
], '_jwt');

if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['success' => 'true']);
