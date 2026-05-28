<?php

/**
 * /API/v1/admin/users.php — CRUD de super-admins (realm /admin, F2).
 *
 * Gateado por adminMiddleware() (JWT _jwt_admin, aud:"admin"). NO apiMiddleware
 * (ese es del realm tenant y exige companyId).
 *
 *   GET                       → lista de admins
 *   GET  ?id=<uuid>           → un admin
 *   POST action=create        → { email, name, password }
 *   POST action=update&id=…   → { email, name, password? }
 *   POST action=setStatus&id= → { status: 0|1 }
 */

require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';
require_once __DIR__ . '/../../../lib/admin/AdminUserService.php';

adminMiddleware(); // define ADMIN_AUTHED_ID o mata con 401

$svc    = new AdminUserService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $admin = $svc->get($id);
        if (!$admin) {
            apiNotFound('Admin no encontrado');
        }
        apiOk($admin);
    }
    apiOk(['rows' => $svc->listAll()]);
}

if ($method === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $res = $svc->create(
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            ADMIN_AUTHED_ID
        );
        if (!$res['ok']) {
            apiError($res['error'], $res['code'] ?? 400);
        }
        apiOk(['id' => $res['id'], 'action' => 'create']);
    }

    if ($action === 'update') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            apiError('Falta id', 422);
        }
        $res = $svc->update(
            $id,
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );
        if (!$res['ok']) {
            apiError($res['error'], $res['code'] ?? 400);
        }
        apiOk(['id' => $id, 'action' => 'update']);
    }

    if ($action === 'setStatus') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            apiError('Falta id', 422);
        }
        $res = $svc->setStatus($id, (int) ($_POST['status'] ?? 0), ADMIN_AUTHED_ID);
        if (!$res['ok']) {
            apiError($res['error'], $res['code'] ?? 400);
        }
        apiOk(['id' => $id, 'action' => 'setStatus']);
    }

    apiError('Acción no soportada', 400);
}

apiError('Método no permitido', 405);
