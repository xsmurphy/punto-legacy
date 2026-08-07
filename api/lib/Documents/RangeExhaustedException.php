<?php
declare(strict_types=1);

namespace Punto\Api\Documents;

/**
 * La secuencia llegó al fin del rango autorizado del timbrado (`rangeto`).
 *
 * Emitir por encima sería emitir fuera de timbrado, así que el documento NO se
 * emite. El caller la deja propagar: al lanzarse dentro de la transacción del
 * documento, el rollback devuelve el número y la secuencia queda intacta.
 */
final class RangeExhaustedException extends \RuntimeException
{
    public function __construct(
        public readonly string $docType,
        public readonly int $rangeTo,
    ) {
        parent::__construct(
            'La numeración de ' . $docType . ' llegó al fin del rango autorizado ('
            . $rangeTo . '). Cargá un timbrado nuevo para seguir emitiendo.'
        );
    }
}
