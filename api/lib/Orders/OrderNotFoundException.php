<?php
declare(strict_types=1);

namespace Punto\Api\Orders;

/**
 * La orden (o el ítem de orden) no existe PARA QUIEN PREGUNTA.
 *
 * Cubre dos casos a propósito, con el mismo mensaje y el mismo 404: que no
 * exista en el tenant, y que exista pero en una sucursal fuera del alcance de
 * quien la pide (el device de otra sucursal, o un usuario del panel con
 * `contact_outlet` que no la incluye). Distinguirlos le diría a un usuario
 * acotado qué ids de orden existen en las sucursales que no puede ver.
 *
 * Es una clase propia y no un `RuntimeException` con mensaje fijo porque el
 * endpoint tiene que mapearla a 404 sin comparar strings: el resto de los
 * errores del service (transición ilegal, motivo faltante) siguen siendo 422.
 */
final class OrderNotFoundException extends \RuntimeException
{
}
