<?php
declare(strict_types=1);

namespace Punto\Api\Sales\Exceptions;

/**
 * La transacción de la venta falló (rollback de PG). El endpoint devuelve 500.
 *
 * `$dbError` ES SOLO PARA LOG. Nunca se devuelve al cliente.
 * -----------------------------------------------------------
 * Antes traía el mensaje crudo de PG únicamente en el camino post-commit
 * (raro). Desde que el wrapper lanza `DbQueryException`, `SaleService::abortSale()`
 * lo llena en TODOS los fallos de venta, así que cualquier endpoint que lo
 * imprima está filtrando el schema (nombres de tablas, columnas, constraints,
 * y a veces valores) al POS y a cualquiera que vea la respuesta. El contrato
 * es el mismo que el de `DbQueryException`: el texto de PG va a `error_log` y
 * a GlitchTip; el cliente recibe `clientMessage()`.
 */
final class SaleAbortedException extends \RuntimeException
{
    /** Mensaje seguro para el cliente — sin nada del schema ni de PG. */
    public const CLIENT_MESSAGE = 'No se pudo registrar la operación. Reintentá; si el problema persiste, avisá a soporte.';

    public function __construct(
        public readonly ?string $dbError = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'Sale transaction aborted');
    }

    /**
     * Lo ÚNICO que puede viajar al cliente. Para el log usá `$e->dbError`.
     */
    public function clientMessage(): string
    {
        return self::CLIENT_MESSAGE;
    }

    /**
     * Clasificación interna: ¿el rollback fue por el guard de stock?
     * Mira el texto CRUDO de PG (server-side) para elegir un CÓDIGO de error,
     * nunca para armar el mensaje que se devuelve.
     */
    public function isStockFailure(): bool
    {
        return $this->dbError !== null && stripos($this->dbError, 'stock') !== false;
    }
}
