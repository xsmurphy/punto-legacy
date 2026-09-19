<?php
declare(strict_types=1);

namespace Punto\Api\Items;

use Punto\App\Domain\ItemUnitCost;

/**
 * Alerta de margen de una compra — junta los datos; `MarginAlert` decide.
 *
 * Flujo (lo orquesta `PurchasesService::create()`, que es el ÚNICO camino de
 * alta de una compra: formulario manual y aprobación de borrador OCR):
 *
 *   1. `snapshot()` ANTES de mover stock: qué artículos puede afectar la
 *      compra y cuánto costaban.
 *   2. la compra mueve el ledger (`manageStock`), que recalcula el promedio.
 *   3. `evaluate()` DESPUÉS: costo nuevo, precio base y veredicto.
 *
 * Artículos afectados:
 *   - los comprados, y
 *   - los que los usan en su receta y se COSTEAN por ella (combo, precombo,
 *     producción directa — `ItemUnitCost::usesRecipe()`), subiendo nivel a
 *     nivel. El costo de esos no se guarda en ningún lado: `RecipeCosting` lo
 *     calcula en vivo desde el ledger, así que una compra de insumo YA les
 *     cambió el costo. Un padre con stock propio (producción previa, ítem que
 *     trackea inventario) corta la subida: su costo es el de SU ledger, que
 *     esta compra no tocó.
 *
 * El costo es el de `ItemUnitCost` — el mismo que congela la venta —, en la
 * sucursal de la compra. `item.itemCost` (costo de catálogo) no participa: la
 * compra no lo actualiza.
 */
final class MarginAlertService
{
    /** Tope de artículos evaluados por compra (guard contra recetas enormes). */
    private const MAX_ITEMS = 500;

    /** Objetivo del comercio (%), o null si la alerta está apagada. */
    public function target(string $companyId): ?float
    {
        $rs = ncmExecute(
            "SELECT config->>'settingObj' AS so FROM company WHERE companyId = ? LIMIT 1",
            [$companyId],
            false,
            true
        );
        $so = null;
        if ($rs && is_object($rs) && !$rs->EOF) {
            $so = $rs->fields['so'] ?? null;
            $rs->Close();
        }
        $obj = json_decode((string) ($so ?? ''), true);

        return MarginAlert::parseTarget(is_array($obj) ? ($obj['marginTarget'] ?? null) : null);
    }

    /**
     * Artículos que la compra puede afectar y su costo ANTES de la compra.
     *
     * @param list<string> $purchasedItemIds
     * @return array<string,array{usesRecipe:bool,before:?float}>
     */
    public function snapshot(string $companyId, string $outletId, array $purchasedItemIds): array
    {
        $affected = [];
        foreach (array_unique(array_filter($purchasedItemIds, 'is_string')) as $id) {
            if ($id !== '' && count($affected) < self::MAX_ITEMS) {
                $affected[$id] = ItemUnitCost::usesRecipe($id, $companyId);
            }
        }

        // Subida por la receta: solo pasa de nivel a través de padres que se
        // costean por receta. `$affected` hace de guard de ciclos.
        $frontier = array_keys($affected);
        while ($frontier !== [] && count($affected) < self::MAX_ITEMS) {
            $parents  = $this->recipeParents($companyId, $frontier);
            $frontier = [];
            foreach ($parents as $pid) {
                if (isset($affected[$pid]) || count($affected) >= self::MAX_ITEMS) {
                    continue;
                }
                if (!ItemUnitCost::usesRecipe($pid, $companyId)) {
                    continue; // costo propio: esta compra no se lo cambia
                }
                $affected[$pid] = true;
                $frontier[]     = $pid;
            }
        }

        $out = [];
        foreach ($affected as $id => $usesRecipe) {
            $out[$id] = [
                'usesRecipe' => $usesRecipe,
                'before'     => ItemUnitCost::resolve($id, $companyId, $outletId, $usesRecipe),
            ];
        }
        return $out;
    }

    /**
     * Veredicto DESPUÉS de la compra.
     *
     * @param array<string,array{usesRecipe:bool,before:?float}> $snapshot
     * @return list<array{itemId:string,name:string,cost:float,price:float,marginPct:float,suggestedPrice:float}>
     */
    public function evaluate(string $companyId, string $outletId, array $snapshot, float $targetPct, bool $decimals): array
    {
        if ($snapshot === []) {
            return [];
        }
        $info = $this->itemInfo($companyId, array_keys($snapshot));

        $rows = [];
        foreach ($snapshot as $id => $s) {
            if (!isset($info[$id])) {
                continue; // archivado u otro tenant
            }
            $rows[] = [
                'itemId'     => (string) $id,
                'name'       => $info[$id]['name'],
                'price'      => $info[$id]['price'],
                'costBefore' => $s['before'],
                'costAfter'  => ItemUnitCost::resolve((string) $id, $companyId, $outletId, $s['usesRecipe']),
            ];
        }

        return MarginAlert::evaluate($targetPct, $rows, $decimals);
    }

    /**
     * Artículos del catálogo que HOY están por debajo del objetivo — la foto,
     * no el evento. Es lo que cuenta la fila "Artículos bajo el margen
     * objetivo" del dashboard.
     *
     * Misma aritmética que la alerta de compra (`MarginAlert::evaluate()`) y
     * mismo costo (`ItemUnitCost`, el que congela la venta); lo único que
     * cambia es que no hay un "antes": se pasa `costBefore = null`, que
     * `evaluate()` ya trata como "el costo pasó a existir", así que el filtro
     * de suba no descarta a nadie y queda solo el de margen.
     *
     * El costo es por sucursal, así que se evalúa en cada sucursal del alcance
     * donde el artículo está dado de alta (`item_outlet`) y cuenta UNA vez si
     * queda bajo en alguna. `[]` = todas las sucursales de la compañía.
     *
     * Barato por construcción: los de stock propio salen en una query por
     * sucursal (`ItemUnitCost::resolveMany`); por receta solo pasan los que
     * TIENEN receta — un servicio sin `item_compound` costaría 0 y `evaluate()`
     * lo descartaría igual, así que costearlo sería trabajo tirado. Con el
     * mismo tope que la alerta de compra.
     *
     * @param list<string> $outletIds
     * @return array{target:float,count:int}|null null = alerta apagada (sin objetivo)
     */
    public function belowTarget(string $companyId, array $outletIds): ?array
    {
        $target = $this->target($companyId);
        if ($target === null) {
            return null;
        }

        $rs = ncmExecute(
            "SELECT i.itemId AS id, i.itemName AS name, i.itemPrice AS price, io.outletid AS outlet,
                    EXISTS (SELECT 1 FROM item_compound ic
                             WHERE ic.parentItemId = i.itemId AND ic.companyId = i.companyId) AS hasrecipe
               FROM item i
               JOIN item_outlet io ON io.itemid = i.itemId AND io.companyid = i.companyId
              WHERE i.companyId = ? AND COALESCE(i.itemStatus, 1) = 1 AND i.itemPrice > 0"
            . \Punto\Api\Outlets\OutletScope::sqlFilter('io.outletid', $outletIds),
            [$companyId],
            false,
            true
        );

        $info = [];     // itemId => [name, price, hasRecipe]
        $byOutlet = []; // outletId => list<itemId>
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f  = $rs->fields;
                $id = (string) $f['id'];
                $hr = $f['hasrecipe'] ?? false;
                $info[$id] = [
                    'name'      => (string) ($f['name'] ?? ''),
                    'price'     => (float) ($f['price'] ?? 0),
                    'hasRecipe' => $hr === true || $hr === 't' || $hr === 1 || $hr === '1',
                ];
                $byOutlet[(string) $f['outlet']][] = $id;
                $rs->MoveNext();
            }
            $rs->Close();
        }
        if ($info === []) {
            return ['target' => $target, 'count' => 0];
        }

        $usesRecipe = ItemUnitCost::usesRecipeMany(array_keys($info), $companyId);

        $below        = [];
        $recipeBudget = self::MAX_ITEMS;
        $truncated    = false;
        foreach ($byOutlet as $outletId => $ids) {
            $evaluable = [];
            foreach ($ids as $id) {
                if (!($usesRecipe[$id] ?? false)) {
                    $evaluable[] = $id;
                } elseif ($info[$id]['hasRecipe'] && !isset($below[$id])) {
                    if ($recipeBudget <= 0) {
                        $truncated = true;
                        continue;
                    }
                    $recipeBudget--;
                    $evaluable[] = $id;
                }
            }
            $costs = ItemUnitCost::resolveMany($evaluable, $companyId, (string) $outletId, $usesRecipe);

            $rows = [];
            foreach ($evaluable as $id) {
                $rows[] = [
                    'itemId'     => $id,
                    'name'       => $info[$id]['name'],
                    'price'      => $info[$id]['price'],
                    'costBefore' => null,
                    'costAfter'  => $costs[$id] ?? null,
                ];
            }
            foreach (MarginAlert::evaluate($target, $rows, false) as $hit) {
                $below[$hit['itemId']] = true;
            }
        }
        if ($truncated) {
            error_log('MarginAlertService::belowTarget: tope de ' . self::MAX_ITEMS
                . ' artículos por receta alcanzado para ' . $companyId . ' — el conteo puede quedar corto');
        }

        return ['target' => $target, 'count' => count($below)];
    }

    /**
     * @param list<string> $childIds
     * @return list<string>
     */
    private function recipeParents(string $companyId, array $childIds): array
    {
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $rs = ncmExecute(
            "SELECT DISTINCT ic.parentItemId AS pid
               FROM item_compound ic
               JOIN item i ON i.itemId = ic.parentItemId AND i.companyId = ?
              WHERE ic.childItemId IN ($ph) AND ic.companyId = ?
                AND COALESCE(i.itemStatus, 1) = 1",
            [$companyId, ...$childIds, $companyId],
            false,
            true
        );
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $out[] = (string) $rs->fields['pid'];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /**
     * Nombre y precio base de los artículos activos del tenant.
     *
     * @param list<string> $ids
     * @return array<string,array{name:string,price:float}>
     */
    private function itemInfo(string $companyId, array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rs = ncmExecute(
            "SELECT itemId AS id, itemName AS name, itemPrice AS price
               FROM item
              WHERE companyId = ? AND itemId IN ($ph) AND COALESCE(itemStatus, 1) = 1",
            [$companyId, ...$ids],
            false,
            true
        );
        $out = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $out[(string) $f['id']] = [
                    'name'  => (string) ($f['name'] ?? ''),
                    'price' => (float) ($f['price'] ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }
}
