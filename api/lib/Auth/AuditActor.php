<?php
declare(strict_types=1);

namespace Punto\Api\Auth;

require_once __DIR__ . '/OperatorAssertion.php';

/**
 * AuditActor — a quién se le atribuye una fila de `tenant_audit`.
 *
 * ── La incoherencia que cierra ──────────────────────────────────────────────
 *
 * El sistema ya AUTORIZA con la identidad correcta y AUDITABA con otra.
 * `AgentActor::authorize()` resuelve al operador real vía `OperatorContext` y
 * evalúa SUS permisos —un cajero y un encargado usando el POS pueden cosas
 * distintas—, pero la fila de auditoría quedaba a nombre del contacto que
 * pareó la tablet hace tres meses, porque `apiAuthTenant()` audita con el
 * `userId` de la CREDENCIAL y bajo el realm `pos-app` esa credencial es una
 * terminal, no una persona (ver el docblock de `OperatorContext`).
 *
 * Autorizar con una identidad y registrar con otra no tiene defensa posible.
 * Y la auditoría es justamente la constancia con la que el comercio se
 * defiende de un "el bot hizo algo que no le pedí": una fila que atribuye mal
 * es peor que no tener ninguna, porque deja registro FORMAL de que lo hizo
 * alguien que no fue.
 *
 * ── Por qué vive en el embudo y no en el asistente ──────────────────────────
 *
 * El bug no es del agente del POS: es de TODA escritura hecha desde una caja
 * con un operador identificado. Un cierre de caja, un descuento o una
 * anulación hechos por un cajero con su PIN se atribuían igual de mal. Por eso
 * esto se resuelve donde se audita —`apiAuthTenant()`— y no en
 * `confirm.php`/`execute.php`: el punto donde cada endpoint decide "quién es
 * la persona" corre DESPUÉS del punto donde el wrapper ya escribió la fila.
 *
 * ── Por qué `OperatorAssertion::verify()` y no `OperatorContext::resolve()` ─
 *
 * Porque esto corre en TODA request mutante autenticada, y para auditar solo
 * hace falta el `userId`. `resolve()` además llama a `roleOf()`, que es una
 * query a `contact` — un SELECT por mutación, en el hot path, para un dato que
 * la fila de auditoría no guarda. `verify()` es HMAC puro sobre el header, sin
 * tocar la BD, y ya trae adentro el aislamiento multi-tenant (el `companyId`
 * va DENTRO de la firma). El permiso se sigue evaluando donde siempre —
 * `OperatorContext`/`AgentActor`—; acá solo se resuelve autoría.
 *
 * ── Qué queda registrado ────────────────────────────────────────────────────
 *
 * Reemplazar el `userId` no puede PERDER de vista qué terminal se usó: un
 * reclamo puede necesitar las dos cosas. Bajo `pos-app` el `meta` jsonb queda
 * siempre auto-descriptivo:
 *
 *   meta.actor        'operator' → la columna `userId` es la PERSONA del PIN
 *                     'device'   → la columna `userId` es el contacto que
 *                                  pareó la tablet, y NO se sabe quién la
 *                                  estaba usando
 *   meta.deviceId     la terminal, en los dos casos
 *   meta.deviceUserId el contacto del pareo, SOLO cuando fue desplazado por el
 *                     operador (con actor='device' ya es la columna `userId`,
 *                     duplicarlo no agregaría nada)
 *
 * Que `actor` esté SIEMPRE presente bajo `pos-app` es lo que vuelve la fila
 * legible sin conocer esta historia: quien lee la tabla distingue "lo hizo
 * Juan" de "lo hizo esta tablet y no sabemos quién". Las filas anteriores a
 * este cambio no lo traen; ausencia bajo `pos-app` = atribución vieja al
 * device.
 *
 * Los realms `panel` y `api` no se tocan: ahí la credencial YA es la persona
 * (o la key, que se identifica con `meta.keyId`).
 */
final class AuditActor
{
    /** La fila se atribuye a una PERSONA, probada con su PIN. */
    public const ACTOR_OPERATOR = 'operator';

    /** No hubo persona probada: la fila queda a nombre de la TERMINAL. */
    public const ACTOR_DEVICE = 'device';

    /**
     * Decide el `userId` de la fila de auditoría y enriquece su `meta`.
     *
     * Nunca lanza: la auditoría es best-effort y no puede tirar una operación
     * ya hecha. Si la verificación de la afirmación falla por lo que sea, se
     * audita como antes de este cambio (al device) y se deja rastro en el log.
     *
     * @param string               $realm            realm de la credencial.
     * @param string               $companyId        tenant de la request.
     * @param string|null          $credentialUserId `userId` que resolvió apiAuthTenant().
     * @param string|null          $deviceId         device del realm pos-app ('' en el resto).
     * @param array<string,mixed>  $meta             meta base que ya armó el caller.
     * @return array{userId: ?string, meta: array<string,mixed>}
     */
    public static function resolve(
        string $realm,
        string $companyId,
        ?string $credentialUserId,
        ?string $deviceId,
        array $meta = []
    ): array {
        $userId = ($credentialUserId !== null && $credentialUserId !== '')
            ? $credentialUserId
            : null;

        // Fuera de `pos-app` la credencial ES la persona (o la API key). No hay
        // nada que desambiguar y no se agrega ruido al meta.
        if ($realm !== 'pos-app') {
            return ['userId' => $userId, 'meta' => $meta];
        }

        $operatorId = null;
        try {
            $operatorId = OperatorAssertion::verify(
                OperatorAssertion::fromRequest(),
                $companyId
            );
        } catch (\Throwable $e) {
            // `verify()` lanza si falta JWT_SECRET. Degradar a la atribución
            // vieja es correcto; abortar la request por no poder auditar, no.
            error_log('[AuditActor] no se pudo verificar el ' . OperatorAssertion::HEADER
                . ': ' . $e->getMessage());
        }

        if ($deviceId !== null && $deviceId !== '') {
            $meta['deviceId'] = $deviceId;
        }

        if ($operatorId === null || $operatorId === '') {
            $meta['actor'] = self::ACTOR_DEVICE;
            return ['userId' => $userId, 'meta' => $meta];
        }

        $meta['actor'] = self::ACTOR_OPERATOR;
        if ($userId !== null) {
            $meta['deviceUserId'] = $userId;
        }

        return ['userId' => $operatorId, 'meta' => $meta];
    }
}
