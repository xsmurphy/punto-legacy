<?php

/**
 * /API/v1/admin/users.php — CRUD de super-admins (realm /admin, F2).
 *
 * Gateado por adminMiddleware() (JWT _jwt_admin, aud:"admin"). NO apiMiddleware
 * (ese es del realm tenant y exige companyId).
 *
 *   GET                       → lista de admins
 *   GET  ?id=<uuid>           → un admin
 *   POST action=create        → { email, name, password, role? }
 *   POST action=update&id=…   → { email, name, password?, role? }
 *   POST action=setStatus&id= → { status: 0|1 }
 *
 * Solo rol 'owner' (bucket "admins" de la matriz F6, ver context/34 §1) — el
 * CRUD de administradores de plataforma es 100% owner-only, incl. lectura.
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/AdminUserService.php';

adminMiddleware();       // define ADMIN_AUTHED_ID o mata con 401
adminRequireRole('owner');

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
        $newEmail = (string) ($_POST['email'] ?? '');
        $newName  = (string) ($_POST['name']  ?? '');
        $newRole  = (string) ($_POST['role']  ?? 'support');
        $res = $svc->create(
            $newEmail,
            $newName,
            (string) ($_POST['password'] ?? ''),
            ADMIN_AUTHED_ID,
            $newRole
        );
        if (!$res['ok']) {
            apiError($res['error'], $res['code'] ?? 400);
        }
        adminAudit('createAdmin', 'admin', $res['id'], $newName, ['email' => $newEmail, 'role' => $newRole]);
        apiOk(['id' => $res['id'], 'action' => 'create']);
    }

    if ($action === 'update') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            apiError('Falta id', 422);
        }
        $updEmail = (string) ($_POST['email'] ?? '');
        $updName  = (string) ($_POST['name']  ?? '');
        $updRole  = array_key_exists('role', $_POST) ? (string) $_POST['role'] : null;
        $res = $svc->update(
            $id,
            $updEmail,
            $updName,
            (string) ($_POST['password'] ?? ''),
            $updRole
        );
        if (!$res['ok']) {
            apiError($res['error'], $res['code'] ?? 400);
        }
        adminAudit('updateAdmin', 'admin', $id, $updName, ['email' => $updEmail, 'role' => $updRole]);
        apiOk(['id' => $id, 'action' => 'update']);
    }

    if ($action === 'setStatus') {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            apiError('Falta id', 422);
        }
        $newStatus = (int) ($_POST['status'] ?? 0);
        $res = $svc->setStatus($id, $newStatus, ADMIN_AUTHED_ID);
        if (!$res['ok']) {
            apiError($res['error'], $res['code'] ?? 400);
        }
        adminAudit('setAdminStatus', 'admin', $id, null, ['status' => $newStatus]);
        apiOk(['id' => $id, 'action' => 'setStatus']);
    }

    apiError('Acción no soportada', 400);
}

apiError('Método no permitido', 405);
