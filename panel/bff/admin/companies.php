<?php

/**
 * /bff/admin/companies.php — proxy de gestión de empresas (realm /admin, F3.1).
 *
 * NO toca BD: reenvía a la API v1 con la cookie _jwt_admin. El front estático
 * standalone (/admin/companies) habla solo con este BFF.
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

bffJson(['ok' => false, 'error' => 'método no permitido'], 405);
