<?php
/**
 * BFF — Datos del admin logueado. GET → reenvía _jwt_admin a la API. 401 si no hay sesión admin.
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_admin'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$res = bffApiGet('v1/admin/me.php', [], '_jwt_admin');
if (!$res['ok']) {
    bffFailFromApi($res);
}
bffJson(['ok' => true, 'data' => $res['data']]);
