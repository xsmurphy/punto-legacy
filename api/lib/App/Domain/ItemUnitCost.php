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
        return self::usesRecipeMany([$itemId], $companyId)[$itemId] ?? false;
    }

    /**
     * `usesRecipe()` para N ítems en UNA query. Un id ilegible (borrado, otro
     * tenant) no aparece en el mapa, y quien lo lea con `?? false` obtiene lo
     * mismo que la versión de a uno: conservador, no explota receta.
     *
     * El predicado es el de `Inventory::EXPLODES_RECIPE_SQL`, el mismo que
     * decide qué descuenta la venta — no una copia.
     *
     * @param list<string> $itemIds
     * @return array<string,bool>
     */
    public static function usesRecipeMany(array $itemIds, string $companyId): array
    {
        $ids = array_values(array_unique(array_filter($itemIds, static fn ($id) => is_string($id) && $id !== '')));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rs = ncmExecute(
            "SELECT itemId AS id,
                    CASE WHEN itemType IN ('precombo', 'combo') OR (" . Inventory::EXPLODES_RECIPE_SQL . ")
                         THEN 1 ELSE 0 END AS uses
               FROM item WHERE companyId = ? AND itemId IN ($ph)",
            array_merge([$companyId], $ids),
            false,
            true
        );
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $out[(string) $rs->fields['id']] = ((int) ($rs->fields['uses'] ?? 0)) === 1;
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /**
     * `resolve()` para N ítems de UNA sucursal. Mismo número que la versión de
     * a uno —es la que congela la venta—, sin el N+1 del lado del ledger: el
     * costo de los ítems con stock propio sale de UNA query (`DISTINCT ON`,
     * misma recencia que `Inventory::getItemStock()`); los que se costean por
     * receta siguen pasando uno por uno por `RecipeCosting`, que es la única
     * fórmula de receta y no tiene versión en bloque.
     *
     * @param list<string>       $itemIds
     * @param array<string,bool> $usesRecipe Mapa ya resuelto (`usesRecipeMany`).
     *        Un id ausente se trata como stock propio.
     * @return array<string,?float> null = no se pudo determinar (nunca 0 por defecto)
     */
    public static function resolveMany(array $itemIds, string $companyId, string $outletId, array $usesRecipe): array
    {
        $out   = [];
        $stock = [];
        foreach ($itemIds as $id) {
            $id = (string) $id;
            if ($id === '') {
                continue;
            }
            if ($usesRecipe[$id] ?? false) {
                $out[$id] = self::resolve($id, $companyId, $outletId, true);
            } else {
                $out[$id]  = null;
                $stock[]   = $id;
            }
        }
        if ($stock === []) {
            return $out;
        }

        $ph = implode(',', array_fill(0, count($stock), '?'));
        $rs = ncmExecute(
            "SELECT DISTINCT ON (itemId) itemId AS id, stockOnHandCOGS AS cogs
               FROM stock
              WHERE itemId IN ($ph) AND outletId = ? AND companyId = ?
              ORDER BY itemId, stockDate DESC, stockId DESC",
            array_merge($stock, [$outletId, $companyId]),
            false,
            true
        );
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $val = $rs->fields['cogs'] ?? null;
                $out[(string) $rs->fields['id']] = is_numeric($val) ? (float) $val : null;
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
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
