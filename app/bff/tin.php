<?php
/**
 * /bff/tin.php — BFF de búsqueda de RUC paraguayo (Marangatu).
 *
 * Reemplaza el handler load=tin de app/load.php. NO toca BD: reenvía a
 * /api/v1/tin.php (cookie _jwt) y devuelve el shape legacy plano que el front
 * consume directo (data.name, data.tin, data.fullName, …).
 */

require_once __DIR__ . '/lib/bff_init.php';

if (($get['load'] ?? '') !== 'tin') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$id      = (string) ($get['id']      ?? '');
$country = (string) ($get['country'] ?? '');

$res = bffApiGet('v1/tin.php', ['id' => $id, 'country' => $country], '_jwt');
if (!$res['ok']) {
    // El front chequea data.error; surface el motivo del API (404 "No se encontraron registros", etc.).
    // bffDecodeEnvelope desempaqueta `{ok:false, error:{message,code}}` a $res['error'] (string).
    $err = is_string($res['error'] ?? null) ? $res['error'] : null;
    bffJson(['error' => $err ?: 'No se encontraron registros']);
}

bffJson(is_array($res['data']) ? $res['data'] : []);
