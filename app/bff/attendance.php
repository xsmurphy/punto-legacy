<?php
/**
 * /bff/attendance.php — BFF de fichaje del POS (Slice 13, cluster ENCOM→Punto).
 *
 * Reemplaza el handler clockIn (action.php L65) que hacía proxy curl a
 * API_ENCOM_URL/set_attendance.php (roto en dev). Reenvía a /api/v1/attendance.php
 * (cookie _jwt) y devuelve el shape legacy { error, type } al top level (el front lee
 * result.type y result.error directamente).
 *
 * Front envía: o=outletId, u=userId, t=token (QR del outlet).
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$get    = json_decode(base64_decode($_GET['l'] ?? ''), true) ?: [];
$action = (string) ($get['action'] ?? '');

if ($action !== 'clockIn') {
    bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
}

$res = bffApiPost('v1/attendance.php', [
    'op'       => 'toggle',
    'outletId' => (string) ($get['o'] ?? ''),
    'userId'   => (string) ($get['u'] ?? ''),
    'token'    => (string) ($get['t'] ?? ''),
], '_jwt');

// El front (debug.js clockIn) sólo lee result.error / result.type — nunca el envelope
// {ok:false} ni el status HTTP. Si usáramos bffFailFromApi, un fallo de auth/transporte
// llegaría con error=undefined → el front mostraría el toast de ÉXITO. Por eso ante
// CUALQUIER fallo devolvemos el shape legacy { error:true, type:null }.
if (!$res['ok']) {
    bffJson(['error' => true, 'type' => null]);
}

// La API devuelve { ok, data:{ error, type } }; el front espera { error, type } plano.
$data = is_array($res['data']) ? $res['data'] : [];
bffJson([
    'error' => $data['error'] ?? true,
    'type'  => $data['type']  ?? null,
]);
