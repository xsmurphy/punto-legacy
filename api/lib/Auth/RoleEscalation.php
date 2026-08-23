<?php
require_once __DIR__ . '/RoleService.php';

/**
 * Regla ÚNICA de anti-escalación de privilegios del tenant.
 *
 * Nadie puede crear, asignar, editar ni desactivar un rol que tenga permisos
 * que él mismo no tiene. Sin esto, `contacts.user.manage` no significa nada:
 * el que lo tiene se fabrica un usuario con el rol de arriba —o se lo asigna
 * a sí mismo— y se queda con el comercio.
 *
 * Vive acá y no dentro de `/v1/users.php` porque el equipo del tenant se
 * puede mutar por MÁS DE UNA puerta, y una regla escrita en un solo call-site
 * es una regla que la segunda puerta no aplica. Ya pasó exactamente eso: el
 * guard nació en users.php y `ai/execute.php` (acción `create_user` del
 * agente) seguía resolviendo el rol POR NOMBRE contra una lista negra de tres
 * strings —'super admin', 'admin', 'administrador'— que no incluye "Dueño",
 * el nombre del seed owner. Con `ai.agent.elevated` alcanzaba pedirle al
 * agente "creá un usuario con rol Dueño" para saltarse el guard entero.
 * Cualquier camino nuevo que asigne un rol pasa por acá.
 *
 * Se comparan SETS de permisos, no nombres ni slugs: así vale igual para los
 * roles custom que cree el admin, y no depende de mantener una lista negra.
 * El owner pasa siempre — `RoleService::getPermissions()` le devuelve el
 * catálogo completo, así que ningún diff puede quedar no vacío.
 */
final class RoleEscalation
{
    /**
     * Permisos que tiene $targetRoleId y NO tiene $callerRoleId.
     * Vacío = asignar/tocar ese rol no escala. Ordenado, para mensajes estables.
     *
     * @return list<string>
     */
    public static function extraPermissions(?string $targetRoleId, ?string $callerRoleId, string $companyId): array
    {
        $target = self::permsOf($targetRoleId, $companyId);
        if (empty($target)) {
            return []; // rol inexistente o sin permisos: no puede escalar nada
        }
        $caller = self::permsOf($callerRoleId, $companyId);
        $extra  = array_values(array_diff($target, $caller));
        sort($extra);
        return $extra;
    }

    /** Corta 403 (apiError) si tocar $targetRoleId escalaría. Para endpoints REST. */
    public static function assertNoEscalation(?string $targetRoleId, string $what): void
    {
        $extra = self::extraPermissions(
            $targetRoleId,
            defined('ROLE_ID') ? (string) ROLE_ID : null,
            (string) COMPANY_ID
        );
        if (empty($extra)) {
            return;
        }
        apiError('No podés ' . $what . ' un rol con más permisos que el tuyo (te faltan: '
            . self::describe($extra) . ')', 403);
    }

    /**
     * Igual que assertNoEscalation() pero LANZA en vez de cortar la response.
     * Para callers que corren dentro de un loop con try/catch por acción y no
     * pueden hacer `exit` — ej. el ejecutor de acciones del agente IA.
     *
     * $callerRoleId explícito para los callers que no corren bajo las
     * constantes de una request (arneses, jobs). Por default es ROLE_ID, que
     * es lo correcto dentro de un endpoint.
     *
     * @throws InvalidArgumentException
     */
    public static function guardOrThrow(?string $targetRoleId, string $companyId, string $what, ?string $callerRoleId = null): void
    {
        $extra = self::extraPermissions(
            $targetRoleId,
            $callerRoleId ?? (defined('ROLE_ID') ? (string) ROLE_ID : null),
            $companyId
        );
        if (empty($extra)) {
            return;
        }
        throw new InvalidArgumentException('No podés ' . $what . ' un rol con más permisos que el tuyo (te faltan: '
            . self::describe($extra) . ')');
    }

    /** Permisos efectivos de un rol. [] si es nulo/vacío/desconocido. */
    private static function permsOf(?string $roleId, string $companyId): array
    {
        $roleId = trim((string) ($roleId ?? ''));
        if ($roleId === '') {
            return [];
        }
        return RoleService::getPermissions($roleId, $companyId);
    }

    /** Primeras 5 claves faltantes — el mensaje tiene que ser accionable, no un volcado. */
    private static function describe(array $extra): string
    {
        return implode(', ', array_slice($extra, 0, 5)) . (count($extra) > 5 ? ', …' : '');
    }
}
