<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/Auth/RoleService.php';
require_once __DIR__ . '/../lib/Auth/RoleEscalation.php';

$ctx = apiAuthTenant(['panel']);

if (!hasPermission('settings.role.manage')) {
    apiError('Sin permiso', 403);
}

/**
 * Anti-escalación por la puerta de los ROLES.
 *
 * `RoleEscalation` cubre "asignar a alguien un rol más poderoso que el mío"
 * (/v1/users, agente IA), pero acá no se asigna nada: se EDITA la lista de
 * permisos de un rol. Sin este control, `settings.role.manage` era la clave
 * maestra del tenant — un PATCH sobre el rol PROPIO le agrega el catálogo
 * entero y el guard "no cambiás tu propio rol" de users.php nunca se entera
 * porque ningún rol cambió de dueño.
 *
 * Tres reglas, y las tres hacen falta:
 *   - No podés meter en un rol una clave que vos no tenés (si no, la
 *     escalación es directa: se crea/edita un rol con todo y se asigna).
 *     Eso es lo que chequea rolesAssertNoEscalation(), acá abajo.
 *   - No podés TOCAR un rol que hoy tiene permisos que vos no tenés, ni para
 *     editarlo ni para borrarlo. Sin esto la escalación es al revés y no menos
 *     efectiva: el que tiene `settings.role.manage` le vacía los permisos al
 *     rol `Encargado` —o al rol custom del otro admin— y se queda como el
 *     único que puede hacer algo, sin haberse otorgado nada. Es la MISMA
 *     pregunta que ya responde `RoleEscalation` para /v1/users y para el
 *     agente IA ("¿este rol tiene algo que yo no tengo?"), así que se usa esa
 *     regla compartida y no una copia local.
 *   - No podés editar tu PROPIO rol (aunque las dos reglas anteriores pasen):
 *     editarlo es redefinir la jurisdicción desde adentro, y sirve igual para
 *     *sacarse* controles o para dejar el rol en un estado que después nadie
 *     más puede revertir.
 *
 * El owner pasa siempre: `RoleService::getPermissions()` le devuelve el
 * catálogo completo, así que el diff nunca puede quedar no vacío. Su propio
 * rol tampoco es editable, pero eso ya lo rechaza `updateRole()` (los
 * permisos del seed owner son derivados en runtime).
 */
/**
 * roleId del caller ya resuelto a UUID. ROLE_ID puede venir como int legacy
 * ('1','3','4'…) según cómo se emitió la sesión, y compararlo crudo contra el
 * UUID de `?id=` nunca matchea — el guard de auto-edición se saltearía solo.
 */
function rolesCallerRoleId(): string
{
    $mine = (string) ROLE_ID;
    return ctype_digit($mine)
        ? RoleService::resolveLegacyRole((int) $mine, (string) COMPANY_ID)
        : $mine;
}

function rolesAssertNoEscalation(array $permissions): void
{
    $mios  = RoleService::getPermissions((string) ROLE_ID, (string) COMPANY_ID);
    $extra = array_values(array_diff($permissions, $mios));
    if (empty($extra)) {
        return;
    }
    sort($extra);
    apiError('No podés otorgar permisos que vos no tenés (te faltan: '
        . implode(', ', array_slice($extra, 0, 5)) . (count($extra) > 5 ? ', …' : '') . ')', 403);
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
        rolesAssertNoEscalation($permissions);
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
        if ($id === rolesCallerRoleId()) {
            apiError('No podés editar tu propio rol', 403);
        }
        RoleEscalation::assertNoEscalation($id, 'editar');
        $name        = isset($_POST['name']) ? trim((string)$_POST['name']) : null;
        $permissions = isset($_POST['permissions']) ? (array)$_POST['permissions'] : null;
        if ($permissions !== null) {
            rolesAssertNoEscalation($permissions);
        }
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
        // Explícito, no derivado: hoy borrar el rol propio falla igual porque
        // `deleteRole()` rechaza un rol con usuarios asignados y el caller se
        // cuenta a sí mismo, pero eso es un side-effect del conteo, no la
        // regla. Si mañana el caller no estuviera asignado a su propio rol
        // (sesión de servicio, rol heredado), el guard desaparecería solo.
        if ($id === rolesCallerRoleId()) {
            apiError('No podés eliminar tu propio rol', 403);
        }
        RoleEscalation::assertNoEscalation($id, 'eliminar');
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
