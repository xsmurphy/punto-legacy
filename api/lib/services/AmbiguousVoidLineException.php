<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * `SaleVoidService::resolveLineDecisions()` no puede atribuir una decisión
 * de reposición sin ambigüedad: el request mandó una línea identificada solo
 * por `itemId` (sin `itemSoldId`), pero esa venta tiene 2+ líneas con ese
 * mismo `itemId` — aplicar la misma decisión a todas contagiaría el
 * `restock` de una línea a otra que el cajero no quiso tocar (P2, code
 * review de F1+F2, context/40-anulacion-y-nota-credito.md).
 *
 * Se tira DENTRO de la transacción de BD de `void()` (después del UPDATE que
 * marca `voidedAt`) — por eso es una excepción catcheable y no un
 * `apiError()` con `exit` directo, que saltearía `FailTrans()`/
 * `CompleteTrans()`. El caller la mapea a 422 con rollback limpio.
 */
final class AmbiguousVoidLineException extends \RuntimeException
{
}
