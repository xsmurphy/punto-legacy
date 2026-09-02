<?php
declare(strict_types=1);

namespace Punto\Api\Auth;

require_once __DIR__ . '/OperatorAssertion.php';
require_once __DIR__ . '/RoleService.php';
require_once __DIR__ . '/hasPermission.php';

/**
 * OperatorContext — la PERSONA detrás de una request, y qué puede hacer.
 *
 * ── Por qué existe, si ya está `apiAuthTenant()` ────────────────────────────
 *
 * Porque `apiAuthTenant()` responde una pregunta distinta: "¿qué credencial es
 * esta y qué tenant/sucursal/caja le corresponde?". Para el realm `panel` la
 * respuesta incluye a la persona; para `pos-app` NO, y ahí está la asimetría
 * que este archivo resuelve:
 *
 *   realm `panel`    el token es de un humano logueado. `AUTHED_USER_ID` es
 *                    él y `ROLE_ID` es su rol real. Persona = credencial.
 *   realm `pos-app`  el token es de una TABLET. Su `userId` es quien la pareó
 *                    hace tres meses y su rol es el rol `device` — el piso de
 *                    capacidades de una terminal, no los permisos de nadie
 *                    (ver RoleService::SEED_PERMISSIONS['device']). La persona
 *                    que está operando NO está en la credencial.
 *
 * Usar `AUTHED_USER_ID` como "quién hizo esto" en el POS es, entonces, un bug
 * silencioso: atribuye todo al que pareó el dispositivo. Para autorizar por
 * persona hace falta la afirmación de operador (`OperatorAssertion`), que
 * nace del PIN validado server-side en el lockscreen.
 *
 * ── El permiso se evalúa contra el ROL DEL OPERADOR, no el del device ───────
 *
 * Esto es lo que hace que "el encargado puede intervenir el espacio de un mozo"
 * funcione sin abrir un agujero. El helper global `hasPermission()` resuelve
 * contra `ROLE_ID`, que bajo `pos-app` es SIEMPRE el rol `device`: si la
 * excepción de exclusividad colgara de ahí, habría dos opciones y las dos
 * malas — o se le da `pos.space.override` al rol `device` y entonces
 * CUALQUIERA en CUALQUIER tablet puede intervenir cualquier espacio (la regla
 * deja de existir), o no se le da y entonces NADIE puede intervenir desde el
 * POS, ni el dueño.
 *
 * La salida es evaluar el permiso contra el rol del contacto que probó su PIN
 * (`contact.role`). El encargado que se identifica en la tablet trae SUS
 * permisos; el mozo, los suyos. El rol del device sigue gobernando lo que la
 * TERMINAL puede hacer — son dos capas, y las dos aplican.
 */
final class OperatorContext
{
    /** @var array<string,string|null> cache por request: contactId => roleId */
    private static array $roleCache = [];

    /**
     * Resuelve la persona que está operando.
     *
     * @param array<string,mixed> $ctx el array que devuelve apiAuthTenant()
     * @return array{userId: ?string, roleId: ?string, identified: bool}
     *         `userId` null = no hay persona probada. NO es un error: es el
     *         estado normal de un device que todavía no pasó por el
     *         lockscreen. Quien llama decide qué implica (para la exclusividad
     *         de espacios implica "no podés tocar un espacio ajeno").
     */
    public static function resolve(array $ctx): array
    {
        $companyId = (string) ($ctx['companyId'] ?? '');
        $realm     = (string) ($ctx['realm'] ?? '');

        if ($realm !== 'pos-app') {
            // Panel: la credencial ES la persona.
            $userId = defined('AUTHED_USER_ID') ? (string) AUTHED_USER_ID : '';
            $roleId = defined('ROLE_ID') ? (string) ROLE_ID : '';
            return [
                'userId'     => $userId !== '' ? $userId : null,
                'roleId'     => $roleId !== '' ? $roleId : null,
                'identified' => $userId !== '',
            ];
        }

        // pos-app: la persona viaja aparte, probada con el PIN.
        $contactId = OperatorAssertion::verify(OperatorAssertion::fromRequest(), $companyId);
        if ($contactId === null) {
            return ['userId' => null, 'roleId' => null, 'identified' => false];
        }

        return [
            'userId'     => $contactId,
            'roleId'     => self::roleOf($companyId, $contactId),
            'identified' => true,
        ];
    }

    /**
     * ¿El operador resuelto tiene este permiso?
     *
     * Sin operador identificado devuelve false — fail-closed. Un permiso es
     * una capacidad de alguien; sin ese alguien no hay nada que conceder.
     *
     * @param array{userId: ?string, roleId: ?string, identified: bool} $operator
     */
    public static function can(array $operator, string $perm, string $companyId): bool
    {
        $roleId = $operator['roleId'] ?? null;
        if ($roleId === null || $roleId === '') return false;
        return \RoleService::hasPermission($perm, $roleId, $companyId);
    }

    /**
     * Gate de permiso de un ENDPOINT contra la PERSONA que hace la request.
     *
     * ── Por qué no alcanza con `hasPermission()` ────────────────────────────
     *
     * Porque ese helper resuelve contra `ROLE_ID`, y bajo `pos-app` ese rol es
     * SIEMPRE el del device: el gate pasa siempre, para cualquiera que tenga la
     * tablet en la mano, con o sin alguien desbloqueado. Escrito en un endpoint
     * que también sirve al panel, `hasPermission()` da la falsa impresión de
     * estar cerrado cuando por el lado de la caja está abierto de par en par
     * (es lo que documentan `returns.php` y `users.php`: "este gate SIEMPRE
     * pasa").
     *
     * Este método contesta la pregunta que corresponde —"¿la PERSONA que pide
     * esto puede verlo?"— y la contesta igual en las dos superficies:
     *
     *   realm `panel` / `api`   la credencial ES la persona (o la key que ella
     *                           emitió, con SU rol): `hasPermission()`.
     *   realm `pos-app`         la credencial es la TABLET: la persona sale de
     *                           la `OperatorAssertion` del PIN y el permiso se
     *                           evalúa contra SU rol.
     *
     * ── Fail-closed sin operador ────────────────────────────────────────────
     *
     * Sin `X-Operator-Token` válido no hay a quién medirle el permiso, así que
     * no se contesta: 403. No hay caída al rol del device — esa caída es
     * exactamente el agujero que este método cierra. Por eso el gate va solo en
     * endpoints donde la caja NO opera a ciegas: lo que el POS consume sin
     * nadie desbloqueado (catálogo, stock, clientes) sigue con su gate de
     * device, que es el piso correcto para una terminal.
     *
     * Es la MISMA puerta que ya usan las escrituras del agente
     * (`Punto\Api\Ai\AgentActor`), que también resuelve por realm y evalúa con
     * `can()`. Acá se expone como guard de endpoint para que una LECTURA no
     * necesite instanciar el actor del agente ni inventar un segundo camino de
     * autorización.
     *
     * @param array<string,mixed> $ctx el array que devuelve apiAuthTenant()
     */
    public static function requirePermission(array $ctx, string $perm): void
    {
        self::requireAnyPermission($ctx, [$perm]);
    }

    /**
     * Igual que `requirePermission()`, pero alcanza con UNA de las claves.
     *
     * No es un aflojamiento del gate: existe porque hay recursos a los que se
     * llega legítimamente por más de una capacidad. El caso que lo motivó es el
     * DETALLE de una transacción: verlo es parte del reporte de ventas
     * (`reports.sales.view`), pero también de cobrarle a un cliente
     * (`pos.sale.creditPayment`) o de anular un recibo (`pos.sale.void`), y un
     * cajero que puede hacer lo segundo necesita abrir el documento sobre el
     * que va a operar. Exigir la clave del reporte para eso sería pedir el
     * permiso equivocado y romper el cobro en el panel.
     *
     * La regla sigue siendo la misma: quien pide tiene que poder ver eso por
     * ALGUNA vía que ya tenga concedida. Una lista vacía no autoriza a nadie.
     *
     * @param array<string,mixed> $ctx   el array que devuelve apiAuthTenant()
     * @param list<string>        $perms cualquiera de estas habilita
     */
    public static function requireAnyPermission(array $ctx, array $perms): void
    {
        $detalle = 'No tenés permiso para esta acción (requiere: ' . implode(' o ', $perms) . ')';

        if ((string) ($ctx['realm'] ?? '') !== 'pos-app') {
            foreach ($perms as $perm) {
                if (\hasPermission($perm)) return;
            }
            \apiError($detalle, 403);
        }

        $companyId = (string) ($ctx['companyId'] ?? '');
        $operator  = self::resolve($ctx);
        if (!$operator['identified']) {
            \apiError('Desbloqueá la caja con tu PIN para consultar esto', 403);
        }
        foreach ($perms as $perm) {
            if (self::can($operator, $perm, $companyId)) return;
        }
        \apiError($detalle, 403);
    }

    /**
     * Rol del contacto. `type = 0` es el filtro de "usuario del comercio"
     * (mismo que usa unlock-pin.php); `contactstatus = 1`, que esté activo —
     * un empleado dado de baja no conserva sus permisos porque su PIN siga
     * en un token emitido antes.
     *
     * PÚBLICA desde 2026-08-25: `/v1/unlock-pin` la necesita para adjuntar los
     * permisos `pos.*` del operador a la respuesta del PIN (ver el docblock de
     * ese endpoint). Se expone en vez de repetir la query allá para que "el rol
     * de este operador" —incluidos los filtros `type`/`contactstatus` y el
     * cache por request— siga teniendo UNA sola definición: si mañana cambia
     * qué contacto cuenta como usuario del comercio, cambia acá y vale para el
     * guard y para el surfacing de permisos a la vez.
     */
    public static function roleOf(string $companyId, string $contactId): ?string
    {
        $key = $companyId . ':' . $contactId;
        if (array_key_exists($key, self::$roleCache)) {
            return self::$roleCache[$key];
        }
        $row = ncmExecute(
            'SELECT role FROM contact
              WHERE contactid = ? AND companyid = ? AND type = 0 AND contactstatus = 1
              LIMIT 1',
            [$contactId, $companyId]
        );
        $role = ($row && isset($row['role']) && (string) $row['role'] !== '')
            ? (string) $row['role']
            : null;
        self::$roleCache[$key] = $role;
        return $role;
    }
}
