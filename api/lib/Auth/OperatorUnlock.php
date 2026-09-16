<?php
declare(strict_types=1);

namespace Punto\Api\Auth;

require_once __DIR__ . '/OperatorAssertion.php';
require_once __DIR__ . '/OperatorContext.php';
require_once __DIR__ . '/RoleService.php';

/**
 * OperatorUnlock — lo que produce el desbloqueo de una caja, y las DOS únicas
 * formas de llegar a él.
 *
 * El desbloqueo entrega tres cosas juntas: quién opera (`user`), la prueba de
 * que el servidor lo verificó (`operatorToken`, ver `OperatorAssertion`) y los
 * permisos de caja de esa persona. Hay dos caminos que pueden verificar a esa
 * persona contra la BD, y los dos tienen que entregar EXACTAMENTE lo mismo:
 *
 *   1. `/v1/unlock-pin` — el PIN tipeado matchea un contacto del tenant.
 *   2. `/v1/unlock-sole` — la sucursal de la caja tiene UN SOLO usuario
 *      habilitado (context/72 §9.3, D-P2): si no hay a quién distinguir, pedir
 *      el PIN no protege nada.
 *
 * Por eso el armado de la respuesta vive acá y no copiado en los dos
 * endpoints: una afirmación emitida sin PIN con otro shape (o con otro filtro
 * de permisos) sería una segunda credencial de operador con reglas propias.
 *
 * ── La regla del camino 2 la decide el SERVIDOR ─────────────────────────────
 * `soleOperator()` cuenta el roster con `UsersService::rosterForOutlet()`, la
 * MISMA consulta que baja en el bootstrap del POS, contra la sucursal que sale
 * del PAREO del device (nunca un valor del request). Si el navegador decidiera
 * "hay uno solo", cualquiera saltaría el PIN en un comercio con empleados
 * (context/72 §9.4). Es dinámica a propósito: no hay flag guardado — el día que
 * se da de alta un segundo usuario, el camino 2 empieza a responder 403 solo.
 */
final class OperatorUnlock
{
    /**
     * ── Permisos del operador (surfacing para la UI de la caja) ──────────────────
     *
     * El backend sigue siendo la autoridad: `SpaceOwnershipGuard` y compañía
     * resuelven contra el rol del operador en CADA request. Esto es lo que le falta
     * al front para no mentir — sin ello solo puede espejar dos de las tres
     * condiciones del guard ("el espacio no tiene mozo", "el espacio es mío") y le queda
     * afuera la tercera ("soy encargado y puedo intervenir"). El resultado sin esto
     * es siempre malo: o se apagan acciones que el encargado SÍ puede ejecutar, o
     * se dejan prendidas y revientan con 403 al tocarlas.
     *
     * Por qué ACÁ y no en el roster del bootstrap (`/v1/users`): ese payload
     * proyecta a propósito id/name/pinhash y nada más —"ni rol"— porque vive para
     * siempre en el localStorage de una tablet compartida. Los permisos son la
     * capacidad de UNA persona sobre sí misma, y este es el único punto del sistema
     * donde el backend comprobó contra la BD quién es esa persona. Se entregan solo
     * a quien acaba de probar su PIN, en la misma respuesta que su afirmación.
     *
     * Filtrados al prefijo `pos.`: el resto del catálogo son permisos de PANEL
     * (reportes, ajustes, contactos). En la caja no gobiernan ninguna UI, así que
     * mandarlos solo agranda la superficie que se cachea en el dispositivo sin
     * habilitar nada.
     *
     * EXTRA_POS_PERMS es la excepción explícita a esa regla, y existe porque la
     * condición que la justifica —"no gobiernan ninguna UI de la caja"— dejó de ser
     * cierta para una clave: desde que el GET de `/v1/reports/transactions` se
     * evalúa contra el rol del OPERADOR (ver el gate en ese archivo),
     * `reports.sales.view` decide si el asistente de la caja puede contestar
     * "¿cuánto se vendió hoy?". Sin bajarla, la UI no tiene forma de saberlo y solo
     * puede hacer dos cosas, las dos malas: prometerle ventas a un cajero que va a
     * recibir 403, o callarlas también para el dueño.
     *
     * Es una ALLOWLIST de claves puntuales, no un ensanche del filtro: cada entrada
     * tiene que gatear algo que se ve o se ofrece en la caja. Sigue sin bajar el
     * catálogo de panel.
     *
     * Su contraparte del lado del front es `POS_TOOL_PERMISSION`
     * (`frontend/lib/pos/agent-tools.ts`): una clave que gatee una tool allá y no
     * esté acá NUNCA llega al dispositivo, así que la tool queda apagada para
     * todos. Se agregan juntas.
     */
    private const EXTRA_POS_PERMS = ['reports.sales.view'];

    /**
     * El usuario habilitado de la sucursal, si es EXACTAMENTE uno. `null` si
     * hay cero o más de uno — incluido el `$outletId` vacío, que en
     * `rosterForOutlet()` significa "sin filtro de sucursal" y acá sería
     * contar el tenant entero en vez de la caja: se corta antes.
     *
     * @return array{id:string,name:string}|null
     */
    public static function soleOperator(string $companyId, string $outletId): ?array
    {
        if ($companyId === '' || $outletId === '') {
            return null;
        }
        $roster = (new \Punto\Api\Users\UsersService())->rosterForOutlet($companyId, $outletId);
        if (count($roster) !== 1) {
            return null;
        }
        $only = $roster[0];
        $id   = (string) ($only['id'] ?? '');
        if ($id === '') {
            return null;
        }
        return ['id' => $id, 'name' => (string) ($only['name'] ?? '')];
    }

    /**
     * Payload del desbloqueo. SOLO se llama tras verificar al operador contra
     * la BD (PIN correcto o `soleOperator()` no-null) — misma condición que
     * exige `OperatorAssertion::issue()`.
     *
     * @return array{user:array{id:string,name:string},operatorToken:string,permissions:list<string>}
     */
    public static function grant(string $companyId, string $contactId, string $contactName): array
    {
        $role  = OperatorContext::roleOf($companyId, $contactId);
        $perms = $role !== null
            ? array_values(array_filter(
                \RoleService::getPermissions($role, $companyId),
                static fn($perm) => str_starts_with((string) $perm, 'pos.')
                    || in_array((string) $perm, self::EXTRA_POS_PERMS, true)
            ))
            : [];

        return [
            'user' => [
                'id'   => $contactId,
                'name' => $contactName,
            ],
            'operatorToken' => OperatorAssertion::issue($companyId, $contactId),
            'permissions'   => $perms,
        ];
    }
}
