<?php

declare(strict_types=1);

namespace Punto\Api\Sales;

/**
 * El timbrado de la caja estaba vencido en la FECHA DE LA OPERACIÓN.
 *
 * Mismo contrato que `OrderCancelBlockedException` (context/20): el mensaje es
 * para el humano y `details()` lleva la clasificación que el POS necesita para
 * explicarlo sin re-derivarla del texto. El endpoint la traduce a
 * `apiError($e->getMessage(), 422, $e->details())`.
 */
final class InvoiceAuthExpiredException extends \RuntimeException
{
    /** @param array<string,mixed> $details */
    public function __construct(
        private readonly array $details,
        string $message,
    ) {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
