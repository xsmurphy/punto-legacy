<?php
declare(strict_types=1);

namespace Punto\Api\Admin;

/**
 * Fallo del migrador (context/77), con el código HTTP que le corresponde.
 *
 * El mensaje está redactado para que lo LEA EL OPERADOR DE SOPORTE, no para
 * depurar: viaja tal cual al endpoint de /admin y al log del job. Nunca
 * incluye la password ni el cuerpo crudo de la respuesta del legacy.
 */
final class EncomMigrationException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
