<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * El ítem quedó FUERA de la ventana de anulación que configuró el comercio
 * (`settingOrderItemCancelWindowMinutes`) y el operador no tiene
 * `pos.order.item.cancel.late`.
 *
 * Es una regla de NEGOCIO, no un fallo de infraestructura ni una transición
 * inválida. Viaja con `details()` —el mismo payload que el 422 del endpoint—
 * para que el POS pueda decir "pasaron 37 minutos y la ventana es de 5" y
 * ofrecer la salida real (que un encargado se identifique con su PIN), en vez
 * de un "no se pudo" sin explicación.
 *
 * Mismo patrón que `ShiftCloseBlockedException`: la excepción TRANSPORTA el
 * payload que arma el gate, no lo reformatea. Un solo formato para el error y
 * para cualquier consulta previa que quiera anticiparlo.
 *
 * Extiende `\RuntimeException` y no `\InvalidArgumentException` a propósito: el
 * pedido del cliente es válido: lo que falta es una autorización, y `orders-core.php`
 * la distingue con un `catch` propio ANTES del genérico.
 */
final class OrderItemCancelBlockedException extends \RuntimeException
{
    /** @param array<string,mixed> $details Payload de OrderItemCancelGate */
    public function __construct(private readonly array $details, string $message)
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
