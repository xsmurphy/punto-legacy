<?php
declare(strict_types=1);

namespace Punto\Api\Wallet;

/**
 * Regla de negocio de la wallet que no se cumple (context/74).
 *
 * El `code` es el HTTP que le corresponde (422 dato inválido, 404 no existe,
 * 409 conflicto de estado). El mensaje va tal cual a la pantalla, así que es
 * accionable y sin tecnicismos (context/14 Regla #8).
 */
class WalletException extends \RuntimeException
{
    public function __construct(string $message, int $httpStatus = 422)
    {
        parent::__construct($message, $httpStatus);
    }
}
