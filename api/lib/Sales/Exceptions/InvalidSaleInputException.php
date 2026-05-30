<?php
declare(strict_types=1);

namespace Punto\Api\Sales\Exceptions;

/**
 * Payload de venta inválido o incompleto. El endpoint devuelve 422.
 * La cola offline NO debe reintentar — el payload no va a volverse válido.
 */
final class InvalidSaleInputException extends \InvalidArgumentException
{
}
