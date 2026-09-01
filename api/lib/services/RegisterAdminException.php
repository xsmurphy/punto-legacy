<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * Una regla de negocio de `RegisterAdminService` rechazó la operación: nombre
 * de caja repetido, punto de expedición ya usado con el mismo timbrado, número
 * que pisaría una factura emitida, última caja de la sucursal.
 *
 * ── Por qué existe (2026-09-01) ─────────────────────────────────────────────
 *
 * Esas reglas respondían con `apiError()`, que hace `exit`. Servía mientras el
 * único consumidor era `/v1/register` —un endpoint, una operación, una
 * respuesta—, pero dejó de servir cuando el ejecutor del agente IA
 * (`/v1/ai/execute`) pasó a poder crear cajas: ahí las acciones corren en un
 * LOTE cuyo contrato es que un fallo NO aborta las demás y cada resultado se
 * reporta por separado. Un `exit` a mitad del loop rompe las dos mitades del
 * contrato: mata las acciones que faltaban y, peor, se lleva puesto el reporte
 * de las que YA se ejecutaron — el usuario recibe un error suelto sin enterarse
 * de que se le crearon dos usuarios y una caja. Un onboarding así se reintenta
 * a ciegas y duplica lo ya hecho.
 *
 * La alternativa era revalidar el timbrado en el ejecutor del agente, o sea
 * tener la regla fiscal escrita en dos lugares: exactamente lo que no puede
 * pasar con la unicidad del punto de expedición (context/29), donde la segunda
 * copia desactualizada emite facturas duplicadas y eso es ilegal ante la SET.
 *
 * Así que la regla se queda donde estaba y lo que cambia es cómo VIAJA: el
 * servicio lanza, y cada consumidor decide. `/v1/register` la traduce a la
 * misma respuesta HTTP de antes (mensaje y status idénticos, sin cambio de
 * contrato para el panel); el loop del agente la atrapa y la reporta como el
 * error de ESA acción.
 *
 * El status HTTP viaja en `getCode()` — es el mismo que pasaba `apiError()`
 * (404 no encontrada, 409 conflicto, 422 payload inválido) y los mensajes son
 * los que ya se le mostraban al usuario, así que también sirven tal cual como
 * la explicación que el agente le repite al cliente.
 */
final class RegisterAdminException extends \RuntimeException
{
    public function __construct(string $message, int $httpCode = 422)
    {
        parent::__construct($message, $httpCode);
    }

    /** Status HTTP con el que el consumidor debería responder. */
    public function httpCode(): int
    {
        $code = $this->getCode();
        return $code >= 400 && $code < 600 ? (int) $code : 422;
    }
}
