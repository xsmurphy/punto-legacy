<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

use Punto\Api\Auth\OperatorContext;

require_once __DIR__ . '/SpaceOwnershipException.php';
require_once __DIR__ . '/../Auth/OperatorContext.php';

/**
 * SpaceOwnershipGuard — exclusividad de espacio por mozo.
 *
 * Regla (pedido del owner 2026-08-23): un espacio asignado a un mozo NO lo puede
 * modificar otro. Textual del pedido: es autorización, no UI — tiene que valer
 * en el backend, no escondiendo botones.
 *
 * ── Dónde vive el enforcement, y por qué acá ────────────────────────────────
 *
 * En el SERVICE, no en el endpoint. `SpaceSessionService` es la única puerta a
 * las transiciones de una sesión, y ya la usan tres callers distintos (el
 * endpoint `/v1/space-sessions.php`, `OrderCoreService` y
 * `SpaceSettlementService`). Un guard por endpoint deja las otras dos puertas
 * abiertas y obliga a acordarse de repetirlo en la cuarta. Acá el chequeo
 * corre aunque un call-site nuevo lo ignore, que es la definición de un
 * invariante y no de una validación.
 *
 * ── Qué cuenta como "asignada" ──────────────────────────────────────────────
 *
 * `space_session.waiterid IS NOT NULL`. Asignar el mozo ES asignar el espacio: no
 * hay un segundo flag que active la exclusividad. Consecuencia buscada, y la
 * razón de que este cambio no rompa a nadie: los espacios que se abren sin mozo
 * (todo lo que existe hoy) siguen siendo de todos. El comercio que no quiere
 * exclusividad simplemente no asigna mozo.
 *
 * ── Quién puede intervenir igual ────────────────────────────────────────────
 *
 * Quien tenga `pos.space.override` (catálogo, seed de `manager`). Un encargado
 * TIENE que poder cerrar el espacio del mozo que se fue a su casa; una regla sin
 * válvula de escape se termina evadiendo compartiendo el PIN del dueño.
 *
 * OJO con dónde se evalúa ese permiso: contra el rol del OPERADOR
 * (`OperatorContext`), nunca contra `hasPermission()` global, que bajo
 * `pos-app` mira el rol del DEVICE y le respondería lo mismo a todos los que
 * usan esa tablet. Ver el docblock de `OperatorContext`.
 *
 * ── Operador no identificado = no pasa ──────────────────────────────────────
 *
 * Fail-closed. Sin PIN validado no hay forma de saber si sos el dueño de la
 * espacio, y "no sé quién sos" no puede resolverse a favor: sería la manera
 * trivial de saltear la regla (no mandar el header). El costo es que el mozo
 * tiene que estar identificado en la caja para tocar SU espacio, que es
 * exactamente lo que el lockscreen ya pide.
 */
final class SpaceOwnershipGuard
{
    /** Clave del catálogo que destraba el espacio ajeno. */
    public const OVERRIDE_PERMISSION = 'pos.space.override';

    /**
     * @param array<string,mixed> $sessionRow fila CRUDA de space_session (con `waiterid`)
     * @param array{userId: ?string, roleId: ?string, identified: bool} $operator
     * @param string $action verbo para el mensaje de error ("cancelar", "mover"...)
     *
     * @throws SpaceOwnershipException
     */
    public static function assert(array $sessionRow, array $operator, string $companyId, string $action): void
    {
        $waiterId = isset($sessionRow['waiterid']) ? trim((string) $sessionRow['waiterid']) : '';

        // Espacio sin mozo asignado: no es de nadie, lo opera cualquiera.
        if ($waiterId === '') return;

        $operatorId = $operator['userId'] ?? null;

        // El dueño del espacio, siempre.
        if ($operatorId !== null && hash_equals($waiterId, $operatorId)) return;

        // La válvula de escape del encargado.
        if (OperatorContext::can($operator, self::OVERRIDE_PERMISSION, $companyId)) return;

        if ($operatorId === null) {
            throw new SpaceOwnershipException(
                "Este espacio está asignado a un mozo. Identificate con tu PIN para $action."
            );
        }
        throw new SpaceOwnershipException(
            "Este espacio está asignado a otro mozo y no lo podés $action."
        );
    }
}
