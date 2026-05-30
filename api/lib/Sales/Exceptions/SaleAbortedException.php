<?php
declare(strict_types=1);

namespace Punto\Api\Sales\Exceptions;

/**
 * La transacción de la venta falló (rollback de PG). El endpoint devuelve 500.
 * `$dbError` contiene el mensaje crudo de PG para debug; el front recibe un
 * shape de error sin filtrar internals salvo en dev.
 */
final class SaleAbortedException extends \RuntimeException
{
    public function __construct(
        public readonly ?string $dbError = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? 'Sale transaction aborted');
    }
}
