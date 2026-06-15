<?php
/**
 * REST canónico (API compartida /api) — Equipo / empleados (contact type=0).
 *
 *   GET    /v1/users                      → lista de empleados (q, status, limit, offset)
 *   GET    /v1/users?id=<uuid>            → detalle del empleado
 *   GET    /v1/users?resource=roles       → roles disponibles de la empresa
 *   POST   /v1/users                      → crea empleado (body JSON: name, password, email, …)
 *   PUT    /v1/users?id=<uuid>            → update parcial (body JSON)
 *   DELETE /v1/users?id=<uuid>            → desactiva (soft — contactStatus = 0)
 *
 * Auth: realm `panel` — solo los administradores del panel gestionan el equipo.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Users/UsersService.php';

$ctx      = apiAuthTenant(['panel']);
$svc      = new \Punto\Api\Users\UsersService();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id']       ?? null;
$resource = $_GET['resource'] ?? null;

// ── Sub-recurso: roles ─────────────────────────────────────────────────────
if ($resource === 'roles') {
    if ($method !== 'GET') apiError('Method not allowed', 405);
    apiOk(['roles' => $svc->roles(COMPANY_ID)]);
}

// ── JSON body → body array ─────────────────────────────────────────────────
$body = [];
$_raw = file_get_contents('php://input');
if (is_string($_raw) && $_raw !== '') {
    $_json = json_decode($_raw, true);
    if (is_array($_json)) $body = $_json;
}

// ── Recurso principal ──────────────────────────────────────────────────────
switch ($method) {
    case 'GET':
        if ($id !== null) {
            $user = $svc->get($id, COMPANY_ID);
            if ($user === null) apiError('Usuario no encontrado', 404);
            apiOk($user);
        }
        apiOk([
            'users' => $svc->list(COMPANY_ID, [
                'q'      => $_GET['q']      ?? null,
                'status' => $_GET['status'] ?? null,
            ]),
        ]);
        break;

    case 'POST':
        try {
            $newId = $svc->create(COMPANY_ID, $body);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 500);
        }
        $user = $svc->get($newId, COMPANY_ID);
        apiOk($user ?? ['id' => $newId], 201);
        break;

    case 'PUT':
        if ($id === null) apiError('id es requerido para PUT', 422);
        try {
            if (!$svc->update($id, COMPANY_ID, $body)) {
                apiError('Update falló', 500);
            }
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        }
        $user = $svc->get($id, COMPANY_ID);
        apiOk($user ?? ['id' => $id]);
        break;

    case 'DELETE':
        if ($id === null) apiError('id es requerido para DELETE', 422);
        if (!$svc->setStatus($id, COMPANY_ID, 0)) {
            apiError('No se pudo desactivar el usuario', 500);
        }
        apiOk(['id' => $id, 'status' => 0]);
        break;

    default:
        apiError('Method not allowed', 405);
}
