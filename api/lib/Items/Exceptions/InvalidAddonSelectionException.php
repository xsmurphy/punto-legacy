<?php
declare(strict_types=1);

namespace Punto\Api\Items\Exceptions;

/**
 * Selección de add-ons inválida (opción inexistente, repetida, grupo dado de
 * baja, min/max o maxQty violados). F3, context/41.
 *
 * Por qué una excepción y no `apiError(..., 422)` como el resto de
 * AddonService: el validador lo consume la VENTA, y la venta no siempre corre
 * en un request de una sola operación —`api/v1/offline-sync.php` procesa un
 * LOTE de ventas encoladas y mapea el error de cada una por separado. Un
 * `apiError()` (que es `never` → `exit`) mataría el request entero y dejaría
 * las ventas siguientes del lote sin procesar ni reportar. El caller la
 * traduce al mecanismo de errores que ya usa la venta
 * (`InvalidSaleInputException` → 422).
 *
 * El CRUD HTTP de add-ons (`api/v1/item_addons.php`) sigue con `apiError` —
 * ahí sí el request es una sola operación y cortar es correcto.
 */
final class InvalidAddonSelectionException extends \InvalidArgumentException
{
}
