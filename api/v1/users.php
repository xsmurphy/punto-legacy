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
require_once __DIR__ . '/../lib/Auth/RoleService.php';

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$allowedRealms = $method === 'GET' ? ['panel', 'pos-app'] : ['panel'];
$ctx      = apiAuthTenant($allowedRealms);
$svc      = new \Punto\Api\Users\UsersService();
$id       = $_GET['id']       ?? null;
$resource = $_GET['resource'] ?? null;

// ── Anti-escalación de privilegios ──────────────────────────────────────────
// `contacts.user.manage` autoriza a gestionar el equipo, NO a fabricarse un
// rol más alto que el propio. Sin estos guards el permiso no significa nada:
// un Encargado podía crearse un segundo usuario con rol Dueño, o directamente
// asignarse Dueño a sí mismo, y quedarse con el tenant entero.
//
// La regla es una sola y se aplica a las tres mutaciones: NADIE puede tocar
// (crear, asignar, editar o desactivar) un rol que tenga permisos que él
// mismo no tiene. Se compara el SET de permisos efectivo, no el nombre ni el
// slug del rol — así vale igual para los roles custom que el admin cree.
// El owner pasa siempre: RoleService::getPermissions() le devuelve el
// catálogo completo, así que ningún diff puede ser no vacío.

/** Permisos efectivos de un rol del tenant. [] si el rol es nulo/desconocido. */
function usersRolePerms(?string $roleId): array
{
    $roleId = trim((string) ($roleId ?? ''));
    if ($roleId === '') return [];
    return RoleService::getPermissions($roleId, (string) COMPANY_ID);
}

/**
 * Corta 403 si $targetRoleId tiene algún permiso que el rol del caller no
 * tiene. $what describe la operación para el mensaje de error.
 */
function usersAssertNoEscalation(?string $targetRoleId, string $what): void
{
    $targetPerms = usersRolePerms($targetRoleId);
    if (empty($targetPerms)) return;

    $callerPerms = usersRolePerms(defined('ROLE_ID') ? (string) ROLE_ID : '');
    $extra = array_values(array_diff($targetPerms, $callerPerms));
    if (empty($extra)) return;

    sort($extra);
    apiError(
        "No podés $what un rol con más permisos que el tuyo (te faltan: "
            . implode(', ', array_slice($extra, 0, 5))
            . (count($extra) > 5 ? ', …' : '') . ')',
        403
    );
}

// ── Sub-recurso: roles ─────────────────────────────────────────────────────
if ($resource === 'roles') {
    if ($method !== 'GET') apiError('Method not allowed', 405);
    if (($ctx['realm'] ?? '') !== 'panel') apiError('Forbidden', 403);
    // El catálogo de roles solo se necesita para asignar uno.
    if (!hasPermission('contacts.user.manage')) {
        apiError('No tenés permiso para esta acción (requiere: contacts.user.manage)', 403);
    }
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
        // El realm `pos-app` también lee esta lista (roster de la pantalla de
        // bloqueo del POS, ya sin `lockPass`). Pasa igual: la sesión del
        // device se emite con roleId='1' → seed `owner` (DeviceAuth::buildToken).
        if (!hasPermission('contacts.user.view')) {
            apiError('No tenés permiso para esta acción (requiere: contacts.user.view)', 403);
        }
        if ($id !== null) {
            $user = $svc->get($id, COMPANY_ID);
            if ($user === null) apiError('Usuario no encontrado', 404);
            if (($ctx['realm'] ?? '') === 'pos-app') {
                unset($user['lockPass']);
            }
            apiOk($user);
        }
        $users = $svc->list(COMPANY_ID, [
            'q'      => $_GET['q']      ?? null,
            'status' => $_GET['status'] ?? null,
        ]);
        if (($ctx['realm'] ?? '') === 'pos-app') {
            foreach ($users as &$u) {
                unset($u['lockPass']);
            }
            unset($u);
        }
        apiOk(['users' => $users]);
        break;

    case 'POST':
        if (!hasPermission('contacts.user.manage')) {
            apiError('No tenés permiso para esta acción (requiere: contacts.user.manage)', 403);
        }
        usersAssertNoEscalation($body['roleId'] ?? null, 'crear un usuario con');
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
        if (!hasPermission('contacts.user.manage')) {
            apiError('No tenés permiso para esta acción (requiere: contacts.user.manage)', 403);
        }
        $target = $svc->get($id, COMPANY_ID);
        if ($target === null) apiError('Usuario no encontrado', 404);
        // Nadie edita su propio rol — ni siquiera para "bajárselo". Un cambio
        // de rol propio es la vía más corta a la escalación y no tiene un
        // caso de uso legítimo: lo hace otro usuario con permiso.
        if (array_key_exists('roleId', $body) && $id === (string) ($ctx['userId'] ?? '')) {
            apiError('No podés cambiar tu propio rol', 403);
        }
        // El rol ACTUAL del target: sin esto un Encargado podía editar (o
        // resetear la contraseña de) el Dueño.
        usersAssertNoEscalation($target['roleId'] ?? null, 'editar un usuario con');
        if (array_key_exists('roleId', $body)) {
            usersAssertNoEscalation($body['roleId'] ?? null, 'asignar');
        }
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
        if (!hasPermission('contacts.user.manage')) {
            apiError('No tenés permiso para esta acción (requiere: contacts.user.manage)', 403);
        }
        $targetDel = $svc->get($id, COMPANY_ID);
        if ($targetDel === null) apiError('Usuario no encontrado', 404);
        usersAssertNoEscalation($targetDel['roleId'] ?? null, 'desactivar un usuario con');
        if (!$svc->setStatus($id, COMPANY_ID, 0)) {
            apiError('No se pudo desactivar el usuario', 500);
        }
        apiOk(['id' => $id, 'status' => 0]);
        break;

    default:
        apiError('Method not allowed', 405);
}
