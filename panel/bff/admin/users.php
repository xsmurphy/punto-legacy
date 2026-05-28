<?php

/**
 * /bff/admin/users.php — proxy del CRUD de admins (realm /admin, F2).
 *
 * NO toca BD: reenvía a la API v1 con la cookie _jwt_admin. El front estático
 * standalone (/admin/users) habla solo con este BFF.
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
    }
    $res = bffApiGet('v1/admin/users.php', $query, '_jwt_admin');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

if ($method === 'POST') {
    $payload = [
        'action'   => $_POST['action'] ?? '',
        'id'       => $_POST['id'] ?? '',
        'email'    => $_POST['email'] ?? '',
        'name'     => $_POST['name'] ?? '',
        'password' => $_POST['password'] ?? '',
        'status'   => $_POST['status'] ?? '',
    ];
    $res = bffApiPost('v1/admin/users.php', $payload, '_jwt_admin');
    if (!$res['ok']) {
        bffFailFromApi($res);
    }
    bffJson(['ok' => true, 'data' => $res['data']]);
}

bffJson(['ok' => false, 'error' => 'método no permitido'], 405);
