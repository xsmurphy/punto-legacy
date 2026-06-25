<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/RoleService.php';

$ctx = apiAuthTenant(['panel']);

if (!hasPermission('settings.role.manage')) {
    apiError('Sin permiso', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch ($method) {
    case 'GET':
        apiOk(['roles' => RoleService::getRoles(COMPANY_ID)]);
        break;

    case 'POST':
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name === '') {
            apiError('name requerido', 422);
        }
        $permissions = $_POST['permissions'] ?? null;
        if (!is_array($permissions)) {
            apiError('permissions debe ser un array', 422);
        }
        try {
            $newId = RoleService::createRole($name, $permissions, COMPANY_ID, (string)$ctx['userId']);
        } catch (RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk(['id' => $newId, 'name' => $name, 'permissions' => $permissions], 201);
        break;

    case 'PATCH':
        $id = (string)($_GET['id'] ?? '');
        if ($id === '') {
            apiError('id requerido', 422);
        }
        $name        = isset($_POST['name']) ? trim((string)$_POST['name']) : null;
        $permissions = isset($_POST['permissions']) ? (array)$_POST['permissions'] : null;
        try {
            RoleService::updateRole($id, $name, $permissions, COMPANY_ID);
        } catch (RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk(['id' => $id]);
        break;

    case 'DELETE':
        $id = (string)($_GET['id'] ?? '');
        if ($id === '') {
            apiError('id requerido', 422);
        }
        try {
            RoleService::deleteRole($id, COMPANY_ID);
        } catch (RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk(['id' => $id]);
        break;

    default:
        apiError('Método no permitido', 405);
}
