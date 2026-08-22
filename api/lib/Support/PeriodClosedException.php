<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Se lanza cuando un UPDATE/DELETE choca contra `fn_period_guard()` (mig
 * 157, context/48-escalamiento-de-datos.md D7): la fila cae en un período
 * ya cerrado y es inmutable.
 *
 * Único choke point de lanzamiento: `DB::Execute()`
 * (api/includes/lib/DB.php) — ahí se detecta el literal `period_closed`
 * en el mensaje que levanta el trigger (SQLSTATE PC001) y se relanza como
 * esta excepción tipada, en vez de devolver `false` silencioso como el
 * resto de los errores de PDO. Se elige el DB layer y no un catch por
 * endpoint porque es el único lugar por el que pasan TODAS las
 * escrituras (Query::execute, ncmUpdate/AutoExecute y los `$db->Execute`
 * directos de los servicios) — mismo criterio que otros fixes de wrapper
 * compartido del proyecto (CaseInsensitiveArray, RecordsetIterator).
 *
 * Mapeo a HTTP: si nadie la atrapa, `api/bootstrap.php`
 * (set_exception_handler) responde 409 con este mensaje. Si el caller ya
 * atrapa `\Throwable` para armar su propia respuesta (ej.
 * SaleVoidService::void()), este mensaje amigable es el que se muestra —
 * nunca el DETAIL crudo del trigger.
 */
final class PeriodClosedException extends \RuntimeException
{
    private const DEFAULT_MESSAGE = 'El período está cerrado; corregí con un documento nuevo (nota de crédito, ajuste de stock, movimiento de caja).';

    /**
     * $dbMessage se recibe (para debug/logging vía $previous) pero NUNCA se
     * muestra tal cual — el DETAIL crudo del trigger no es apto para
     * usuario final. $friendlyMessage permite a un pre-check aplicativo
     * (ej. CreditPaymentService::void()) dar un mensaje específico al caso
     * (qué documento, qué corrección hacer) sin dejar de ser esta MISMA
     * excepción tipada — así bootstrap.php la mapea a 409 sin necesidad de
     * un catch/mapeo nuevo por endpoint.
     */
    public function __construct(
        string $dbMessage = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $friendlyMessage = null
    ) {
        parent::__construct($friendlyMessage ?? self::DEFAULT_MESSAGE, $code, $previous);
    }
}
