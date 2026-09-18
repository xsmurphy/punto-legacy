<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Costo UNITARIO vigente de un ítem en una sucursal — fuente única.
 *
 * Es el número que la venta congela como COGS (`itemSold.itemSoldCOGS`):
 *   - ítem que se vende explotando receta (combo, precombo, producción
 *     directa): `RecipeCosting::total()` en la sucursal indicada.
 *   - ítem con stock propio: el promedio ponderado del ledger
 *     (`stock.stockOnHandCOGS` de la fila vigente).
 *
 * Vivía como `SaleService::resolveUnitCOGS()` (privado). Se extrajo cuando la
 * alerta de margen de compras (`Punto\Api\Items\MarginAlertService`) necesitó
 * el MISMO número: si la alerta calculara el costo por su cuenta, podría
 * avisar "margen bajo" sobre un costo distinto del que después registra la
 * venta. La venta sigue delegando acá, sin cambio de comportamiento.
 *
 * `null` = "no se pudo determinar", NUNCA cero: un 0 se lee como "costó nada"
 * y pinta margen 100%.
 */
final class ItemUnitCost
{
    /** true si el costo del ítem sale de su receta (y no de su propio stock). */
    public static function usesRecipe(string $itemId, string $companyId): bool
    {
        $row      = ncmExecute(
            'SELECT itemType FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        $itemType = (is_array($row) || $row instanceof \ArrayAccess) ? (string) ($row['itemType'] ?? '') : '';

        return in_array($itemType, ['precombo', 'combo'], true)
            || Inventory::saleExplodesRecipe($itemId, $companyId);
    }

    /**
     * @param bool|null $usesRecipe Si el caller ya lo sabe, se pasa para no
     *        repetir el SELECT. `null` = resolverlo acá.
     */
    public static function resolve(string $itemId, string $companyId, string $outletId, ?bool $usesRecipe = null): ?float
    {
        if ($usesRecipe === null) {
            $usesRecipe = self::usesRecipe($itemId, $companyId);
        }

        if ($usesRecipe) {
            // La sucursal es explícita: `RecipeCosting` la exige y tira si
            // falta. Se degrada a null en vez de propagar — para la venta el
            // COGS es dato de reporte, no el hecho económico, y la venta ya
            // emitida no se rechaza por esto.
            try {
                return (float) RecipeCosting::total($itemId, $companyId, $outletId);
            } catch (\InvalidArgumentException $e) {
                error_log('ItemUnitCost: no se pudo costear la receta de ' . $itemId . ' — ' . $e->getMessage());
                return null;
            }
        }

        $stock = Inventory::getItemStock($itemId, $outletId);
        if (!is_array($stock) && !($stock instanceof \ArrayAccess)) {
            return null;
        }
        $val = $stock['stockOnHandCOGS'] ?? null;

        return is_numeric($val) ? (float) $val : null;
    }
}
