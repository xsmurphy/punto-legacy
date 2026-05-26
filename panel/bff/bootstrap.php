<?php
/**
 * BFF — Bootstrap del front.
 *
 *   GET /bff/bootstrap.php → config (currency, decimales, taxName, …) + usuario.
 *
 * Proxy fino sobre GET /API/v1/bootstrap. El front estático lo pide al cargar para
 * formatear números (la presentación vive en el front) y pintar el chrome.
 * NO toca BD, NO sirve HTML. Reenvía el JWT del usuario a la API.
 */

require_once __DIR__ . '/lib/api_client.php';

if (empty($_COOKIE['_jwt_panel'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$res = bffApiGet('v1/bootstrap.php');

if (!$res['ok']) {
    bffFailFromApi($res);
}

bffJson(['ok' => true, 'data' => $res['data']]);
