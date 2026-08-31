<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

/**
 * Lanzada cuando un operador intenta tocar el espacio de otro mozo
 * (`SpaceOwnershipGuard`, exclusividad pedida por el owner 2026-08-23).
 *
 * Tipo propio y no `InvalidArgumentException` porque el endpoint la mapea a
 * **403** y no al 422 genérico del resto de los errores de dominio: "este espacio
 * no es tuya" y "los datos que mandaste no cierran" son respuestas distintas y
 * el front reacciona distinto a cada una.
 *
 * ── Por qué tiene archivo propio ────────────────────────────────────────────
 *
 * El autoloader (api/bootstrap.php:56) resuelve `Punto\Api\X\Y` a `lib/X/Y.php`
 * y nada más: una clase que viva dentro del archivo de OTRA clase solo existe
 * si alguien ya cargó ese archivo. Y el modo de falla sería mudo — `instanceof`
 * contra una clase inexistente no explota, devuelve `false`. El endpoint
 * degradaría el 403 a 422 en silencio, justo en el camino de autorización.
 */
final class SpaceOwnershipException extends \RuntimeException
{
}
