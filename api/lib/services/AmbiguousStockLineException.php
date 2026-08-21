<?php
declare(strict_types=1);

namespace Punto\Api\Services;

/**
 * `StockReversalPolicy::resolveLineDecisions()` no puede atribuir una
 * decisión de reposición sin ambigüedad: el request mandó una línea
 * identificada solo por `itemId` (sin `itemSoldId`), pero hay 2+ opciones
 * con ese mismo `itemId` — aplicar la misma decisión a todas contagiaría el
 * `restock` de una línea a otra que el cajero no quiso tocar (P2, code
 * review de F1+F2 de `SaleVoidService`, context/40-anulacion-y-nota-credito.md,
 * mismo criterio reusado por `ReturnService`).
 *
 * Se tira DENTRO de la transacción de BD del caller (después de escrituras
 * previas en `SaleVoidService::void()`) — por eso es una excepción
 * catcheable y no un `apiError()` con `exit` directo, que saltearía
 * `FailTrans()`/`CompleteTrans()`. El caller la mapea a 422 con rollback
 * limpio.
 *
 * Reemplaza a `AmbiguousVoidLineException` (movida acá al extraer
 * `StockReversalPolicy` de `SaleVoidService` — mismo nombre de mensaje, el
 * test de integración solo verifica el texto/status, no el nombre de la
 * clase).
 */
final class AmbiguousStockLineException extends \RuntimeException
{
}
