<?php
/**
 * /bff/customers.php — BFF de lecturas de cliente del POS.
 *
 * Reemplaza el handler `customerInfo` de app/load.php. NO toca BD: decodifica el
 * sobre `?l=`, reenvía a /api/v1/customers.php (cookie _jwt) y devuelve el objeto
 * plano del resumen del cliente (shape legacy de customerInfo).
 */

require_once __DIR__ . '/lib/bff_init.php';

if ($action === 'customerInfo') {
    // PILOTO BFF-compone: la API expone recursos GRANULARES (profile/recentItems/
    // debt/giftcards/address); el BFF los pide EN PARALELO y arma el shape plano
    // del modal de info. Antes la API armaba todo el set (un endpoint con forma de
    // pantalla); ahora la composición vive acá, que es el trabajo del BFF — la API
    // solo provee datos reusables. Ver context/10-roadmap.md.
    $id = (string) ($get['id'] ?? '');
    $ep = 'v1/customers.php';

    $parts = bffApiGetMulti([
        'profile'     => ['path' => $ep, 'query' => ['resource' => 'profile',     'id' => $id]],
        'recentItems' => ['path' => $ep, 'query' => ['resource' => 'recentItems', 'id' => $id]],
        'debt'        => ['path' => $ep, 'query' => ['resource' => 'debt',        'id' => $id]],
        'giftcards'   => ['path' => $ep, 'query' => ['resource' => 'giftcards',   'id' => $id]],
        'address'     => ['path' => $ep, 'query' => ['resource' => 'address',     'id' => $id]],
    ], '_jwt');

    // El perfil es obligatorio: si no existe el cliente (404) o falla, propagamos.
    if (!$parts['profile']['ok']) {
        bffFailFromApi($parts['profile']);
    }

    // Composición: merge de los recursos en el shape legacy de customerInfo. Los
    // recursos opcionales que fallaron aportan vacío (el modal degrada con gracia).
    $info = array_merge(
        $parts['profile']['data'] ?? [],
        $parts['recentItems']['ok'] ? ($parts['recentItems']['data'] ?? []) : ['latestPurchasedItems' => [], 'lastPurchase' => ''],
        $parts['debt']['ok']        ? ($parts['debt']['data'] ?? [])        : [],
        $parts['giftcards']['ok']   ? ($parts['giftcards']['data'] ?? [])   : ['giftCards' => []],
        $parts['address']['ok']     ? ($parts['address']['data'] ?? [])     : ['customerAddress1' => '', 'latLng' => ''],
        ['notes' => []] // el legacy nunca lo poblaba
    );

    bffJson($info);
}

if ($action === 'customerRecord') {
    $res = bffApiGet('v1/customers.php', [
        'resource' => 'records',
        'id'       => (string) ($get['id'] ?? ''),
    ], '_jwt');
    if (!$res['ok']) bffFailFromApi($res);
    bffJson($res['data']);
}

bffJson(['ok' => false, 'error' => 'operación no soportada'], 400);
