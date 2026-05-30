<?php
declare(strict_types=1);

namespace Punto\Api\Sales\Exceptions;

/**
 * Venta cuyo UID ya existe en la BD — la cola offline reenvió un evento ya
 * procesado. NO es un error: el endpoint devuelve 200 con duplicated=true
 * para que el front marque el UID como sincronizado y no reintente.
 */
final class DuplicateSaleException extends \RuntimeException
{
    public function __construct(
        public readonly string $uid,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Sale UID {$uid} already exists");
    }
}
