<?php
declare(strict_types=1);

namespace Punto\Api\Sales\Exceptions;

use Punto\Api\Sales\ExistingSale;

/**
 * Venta cuyo UID ya existe EN EL TENANT del request — la cola offline reenvió
 * un evento ya procesado, o la caja reintentó un cobro cuyo primer intento sí
 * llegó. NO es un error: el endpoint devuelve 200 con `duplicated=true` y los
 * datos de la venta ORIGINAL (`$existing`), para que el POS muestre ESA venta y
 * no la que intentaba emitir.
 *
 * Invariante (2026-09-16): solo se lanza con `$existing` resuelto por
 * `SaleUidLookup` dentro del tenant. Un 23505 que no se puede atar a una venta
 * visible del tenant NO es un duplicado — es un error real y sale como tal.
 * Antes cualquier 23505 terminaba acá y el POS borraba de su cola una venta
 * impresa que nunca se había guardado.
 */
final class DuplicateSaleException extends \RuntimeException
{
    public function __construct(
        public readonly string $uid,
        public readonly ExistingSale $existing,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Sale UID {$uid} already exists");
    }
}
