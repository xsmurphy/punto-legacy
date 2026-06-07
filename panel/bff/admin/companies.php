<?php

/**
 * /bff/admin/companies.php — proxy de gestión de empresas (realm /admin).
 *
 * NO toca BD: reenvía a la API v1 con la cookie _jwt_admin.
 *
 * GET                             → list / detalle (F3.1)
 * GET ?plans=1                    → lista de planes (F3.4)
 * GET ?id=<uuid>&billing=1        → datos de facturación (F3.4)
 * PATCH ?id=<uuid> body JSON      → update empresa (F3.2)
 * DELETE ?id=<uuid>&type=soft|hard → eliminar empresa (F3.3)
 * POST ?id=<uuid>&action=enter    → JWT impersonar empresa (F3.5)
 */

require_once __DIR__ . '/../lib/api_client.php';

if (empty($_COOKIE['_jwt_admin'])) {
    bffJson(['ok' => false, 'error' => 'no autenticado'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $query = [];

    // F3.4 — planes (selector UI).
    if (!empty($_GET['plans'])) {
        $query['plans'] = '1';
        $res = bffApiGet('v1/admin/companies.php', $query, '_jwt_admin');
        if (!$res['ok']) { bffFailFromApi($res); }
        bffJson(['ok' => true, 'data' => $res['data']]);
    }

    if (!empty($_GET['id'])) {
        $query['id'] = $_GET['id'];
        // F3.4 — billing detail.
        if (!empty($_GET['billing'])) {
            $query['billing'] = '1';
        }
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

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        bffJson(['ok' => false, 'error' => 'falta id'], 400);
    }

    $type = trim((string) ($_GET['type'] ?? 'soft'));
    $body = (in_array($type, ['soft', 'hard'], true) && $type === 'hard')
        ? (string) file_get_contents('php://input')
        : '{}';
    $jwt  = $_COOKIE['_jwt_admin'] ?? '';

    $url = bffApiBase() . '/v1/admin/companies.php?id=' . urlencode($id) . '&type=' . urlencode($type);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,   // hard delete puede tardar en empresas grandes
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
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
        bffJson(['ok' => true, 'deleted' => $json['deleted'] ?? $type]);
    }
    $passthrough = ($status === 401 || $status === 403) ? $status
        : (($status >= 400 && $status < 500) ? $status : 502);
    bffJson(['ok' => false, 'error' => $json['error'] ?? 'error'], $passthrough);
}

if ($method === 'POST') {
    // F3.5 — impersonar empresa: genera _jwt_panel para su propietario.
    $id     = trim((string) ($_GET['id']     ?? ''));
    $action = trim((string) ($_GET['action'] ?? ''));

    if ($id === '' || $action !== 'enter') {
        bffJson(['ok' => false, 'error' => 'parámetros inválidos'], 400);
    }

    $jwt = $_COOKIE['_jwt_admin'] ?? '';
    $url = bffApiBase() . '/v1/admin/companies.php?id=' . urlencode($id) . '&action=enter';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
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
    if (empty($json['ok'])) {
        $passthrough = in_array($status, [401, 403, 404], true) ? $status : 502;
        bffJson(['ok' => false, 'error' => $json['error'] ?? 'error'], $passthrough);
    }

    // Inyectar _jwt_panel en el browser para que acceda al panel como la empresa.
    $token     = (string) ($json['data']['token']     ?? '');
    $expiresIn = (int)    ($json['data']['expiresIn'] ?? 28800);
    if ($token === '') {
        // La API respondió ok:true pero sin token — no hay nada que inyectar.
        bffJson(['ok' => false, 'error' => 'token vacío — la API no devolvió un JWT'], 502);
    }
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('_jwt_panel', $token, [
        'expires'  => time() + $expiresIn,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $isHttps,
    ]);

    // redirectUrl es siempre hardcoded en el servidor — nunca derivar de input del cliente.
    bffJson(['ok' => true, 'redirectUrl' => '/@#dashboard']);
}

bffJson(['ok' => false, 'error' => 'método no permitido'], 405);
