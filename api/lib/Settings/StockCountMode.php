<?php
declare(strict_types=1);

namespace Punto\Api\Settings;

require_once __DIR__ . '/StockCountSettings.php';
require_once __DIR__ . '/../Auth/OperatorContext.php';

use Punto\Api\Auth\OperatorContext;

/**
 * StockCountMode — ¿esta PERSONA, en este comercio, cuenta a ciegas?
 *
 * ÚNICO lugar del codebase que contesta esa pregunta. La consultan el panel
 * (el GET de `/v1/inventory_count`) y la caja (`action=expected`), y tienen
 * que contestarla IGUAL: si cada superficie la resolviera por su cuenta, la
 * que nadie revisa terminaría publicando el esperado que la otra esconde. Es
 * exactamente el modo en que un flag con dos consumidores diverge.
 *
 * ── La granularidad es la PERSONA, no el comercio ──────────────────────────
 *
 * El pedido del cliente fue textual: "le habilitás a un usuario que sea ciego
 * y nuestro usuario tiene libre". O sea que el modo lo decide quién cuenta, no
 * el local. `stockCountBlind` NO desaparece por eso: pasa a significar el PISO
 * del comercio —"acá se cuenta a ciegas salvo que la persona esté habilitada"—
 * y `inventory.count.open` es la habilitación por ROL que lo levanta.
 *
 * Tabla de verdad completa:
 *
 *   stockCountBlind │ tiene inventory.count.open │ modo
 *   ────────────────┼────────────────────────────┼──────────
 *   apagado         │ (no se consulta)           │ ABIERTO
 *   prendido        │ sí                         │ ABIERTO
 *   prendido        │ no                         │ CIEGO
 *   (irresoluble)   │ (irresoluble)              │ CIEGO
 *
 * La primera fila es deliberada y conserva el comportamiento histórico: un
 * comercio que nunca prendió el flag no le exige ciego a nadie, así que pedirle
 * además un permiso apagaría una pantalla que hoy funciona. El permiso solo
 * tiene sentido como EXCEPCIÓN a un piso que existe.
 *
 * ── Fail-closed ────────────────────────────────────────────────────────────
 *
 * Sin companyId no hay flag que leer ni rol contra el cual medir: se devuelve
 * CIEGO. Es la única dirección segura — un error de resolución que revelara el
 * esperado convierte un bug en una fuga de dato, mientras que uno que cuente a
 * ciegas de más solo obliga a contar sin ayuda, que es el default recomendado
 * de la D2 de `context/63`.
 *
 * Bajo `pos-app` el permiso se mide contra el OPERADOR del PIN
 * (`OperatorContext`), NUNCA contra el rol `device`: ese rol es el mismo para
 * todos los que agarran la tablet, así que resolverlo ahí significaría "el que
 * tiene la tablet ve el teórico", justo lo contrario de lo que se pidió. Por
 * eso `inventory.count.open` tampoco va al seed de `device`.
 *
 * ── Y el filtrado lo hace el SERVIDOR ──────────────────────────────────────
 *
 * Este resolver decide, pero no alcanza con que decida: quien no está
 * habilitado no recibe `expectedQty` en la respuesta, en vez de recibirlo con
 * el pedido de que la pantalla no lo pinte. Es el mismo precedente que
 * `drawerBlind` (`api/v1/drawer.php`) y la razón por la que hasta la mig 169 el
 * cierre a ciegas se evadía abriendo las devtools.
 */
final class StockCountMode
{
    /** Clave que levanta el piso de conteo ciego del comercio. */
    public const PERMISSION_OPEN = 'inventory.count.open';

    /**
     * @param array<string,mixed> $ctx el array que devuelve apiAuthTenant()
     */
    public static function isBlind(array $ctx): bool
    {
        $companyId = (string) ($ctx['companyId'] ?? '');
        if ($companyId === '') {
            return true; // fail-closed
        }

        if (!StockCountSettings::forCompany($companyId)->blind()) {
            // El comercio no exige ciego: comportamiento de siempre.
            return false;
        }

        return !self::canCountOpen($ctx);
    }

    /**
     * ¿La persona detrás de la request está habilitada a ver el teórico?
     *
     * Separado de `isBlind()` porque el permiso también sirve para explicar un
     * modo, no solo para elegirlo: con el flag del comercio APAGADO todos
     * cuentan abierto y esta pregunta ni se hace.
     *
     * @param array<string,mixed> $ctx
     */
    public static function canCountOpen(array $ctx): bool
    {
        $companyId = (string) ($ctx['companyId'] ?? '');
        if ($companyId === '') {
            return false;
        }

        $operator = OperatorContext::resolve($ctx);
        if (!$operator['identified']) {
            return false;
        }

        return OperatorContext::can($operator, self::PERMISSION_OPEN, $companyId);
    }
}
