<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * El comercio tiene prendido `settingDrawerRequireClosedOrders` y la caja
 * intenta cerrar el turno con órdenes o espacios todavía abiertos.
 *
 * Es una regla de NEGOCIO, no un fallo de infraestructura, y por eso no viaja
 * como `\RuntimeException` pelada: `api/v1/drawer.php` mapea esas a 500 y hace
 * bien (ahí adentro son errores de DB — ver `DrawerService::close()`). Esta se
 * atrapa ANTES de ese catch y sale como 422 con `details`, así el POS lista qué
 * falta cerrar en vez de mostrar "error del servidor".
 *
 * `blockers()` es literalmente lo que devuelve `ShiftCloseGate::blockers()`:
 * la excepción transporta, no reformatea. Un solo formato para el GET que
 * alimenta el gate del front y para el error del POST — si divergieran, el
 * cajero vería una lista antes de tocar el botón y otra después.
 */
final class ShiftCloseBlockedException extends \RuntimeException
{
    /** @param array<string,mixed> $blockers Payload de ShiftCloseGate::blockers() */
    public function __construct(private readonly array $blockers, string $message)
    {
        parent::__construct($message);
    }

    /** @return array<string,mixed> */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
