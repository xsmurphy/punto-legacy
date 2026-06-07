<?php

/**
 * /bff/admin/companies.php — proxy de gestión de empresas (realm /admin).
 *
 * NO toca BD: reenvía a la API v1 con la cookie _jwt_admin.
 *
 * GET             → list / detalle (F3.1)
 * PATCH ?id=<uuid> body JSON → update empresa (F3.2)
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_admin'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $query = [];
    if (!empty($_GET['id'])) {
        $query['id'] = $_GET['id'];
    } else {
        if (isset($_GET['limit']))  { $query['limit']  = $_GET['limit']; }
        if (isset($_GET['offset'])) { $query['offset'] = $_GET['offset']; }
        if (isset($_GET['q']))      { $query['q']      = $_GET['q']; }
    }

    $res = bffApiGet('v1/admin/companies.php', $query, '_jwt_admin');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

if ($method === 'PATCH') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        bffJson(['ok' => false, 'error' => 'falta id'], 400);
    }

    // bffApiPost usa form-urlencoded; PATCH requiere JSON body → curl inline.
    $body = (string) file_get_contents('php://input');
    $jwt  = $_COOKIE['_jwt_admin'] ?? '';

    $url = bffApiBase() . '/v1/admin/companies.php?id=' . urlencode($id);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Cookie: _jwt_admin=' . rawurlencode($jwt),
        ],
    ]);
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlEr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $curlEr) {
        bffJson(['ok' => false, 'error' => 'transport error'], 502);
    }
    $json = json_decode($resp, true);
    if (!is_array($json)) {
        bffJson(['ok' => false, 'error' => 'respuesta no-JSON de la API'], 502);
    }
    if (!empty($json['ok'])) {
        bffJson(['ok' => true]);
    }
    $passthrough = ($status === 401 || $status === 403) ? $status
        : (($status >= 400 && $status < 500) ? $status : 502);
    bffJson(['ok' => false, 'error' => $json['error'] ?? 'error'], $passthrough);
}

bffJson(['ok' => false, 'error' => 'método no permitido'], 405);
