<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * RecipeCosting — la ÚNICA fórmula de "cuánto cuesta esta receta".
 *
 * ## Por qué existe
 *
 * Hasta 2026-08-22 convivían TRES fórmulas distintas para el mismo número, y
 * el dueño veía tres cifras para una misma hamburguesa (reporte del tester
 * "Actualización 21" #1, `context/10-roadmap.md`):
 *
 *   A. **Ficha del ítem** (`ItemCompoundService::listForParent`):
 *      `Σ qty × item.itemCost` — costo de CATÁLOGO, sin merma, un solo nivel.
 *   B. **Venta de producción directa** (`Inventory::getProductionCOGS`):
 *      `Σ conMerma(qty) × stock.stockOnHandCOGS` — promedio móvil, con merma,
 *      un solo nivel, SIN fallback a `itemCost` (un insumo sin ledger valía
 *      0) y con el outlet de la SESIÓN (`OUTLET_ID`), no el de la venta.
 *   C. **Producción previa** (`Production\ProductionService::complete`):
 *      recursiva vía `explodeRecipe()`, con fallback a `itemCost`, outlet
 *      correcto — la única de las tres que estaba bien.
 *
 * Tres fórmulas para un mismo concepto no divergen "a veces": divergen
 * siempre, y cada consumidor nuevo elegía una al azar. Acá queda una sola, y
 * A/B/C pasan a ser wrappers de ésta.
 *
 * ## La fórmula (regla canónica, C generalizada)
 *
 * 1. **Qué se consume** lo decide `Inventory::explodeRecipeDetailed()` — la
 *    MISMA explosión recursiva que mueve el stock (reglas de corte por
 *    `saleExplodesRecipe()`, merma planificada por nivel, cantidades
 *    multiplicadas nivel a nivel, guard de ciclos). No hay una segunda
 *    recursión acá: si el costeo explotara distinto que el stock, el COGS
 *    dejaría de valuar lo que la operación realmente descontó.
 * 2. **Cuánto vale cada hoja**: `stock.stockOnHandCOGS` de la sucursal
 *    indicada si es > 0 (promedio ponderado real del ledger), y si no
 *    `item.itemCost` (costo de catálogo). Es la regla que ya usaban
 *    `getComboCOGS()` (fix F0, context/23) y `ProductionService::complete()`
 *    — un insumo que nunca tuvo movimiento de stock NO vale 0.
 * 3. **La sucursal es un parámetro obligatorio**, nunca `OUTLET_ID`. El costo
 *    de un insumo es por sucursal; resolverlo con "la sucursal de la sesión"
 *    costea una venta de la sucursal B con el promedio de la A (misma clase
 *    de bug que la regla de las 5 dimensiones obligatorias de la
 *    transacción). Los wrappers legacy son los que hacen el default a
 *    `OUTLET_ID`, para no romper sus callers; este servicio no.
 *
 * El resultado es SIEMPRE por UNA unidad del ítem padre. Es lineal en las
 * unidades (merma incluida), así que el costo de N unidades es `total × N` —
 * por eso `itemSold.itemSoldCOGS` guarda el costo UNITARIO y los reportes lo
 * multiplican por `itemSoldUnits`.
 */
final class RecipeCosting
{
    /** Hoja valuada con el promedio ponderado real del ledger de stock. */
    public const SOURCE_AVG = 'avg';

    /** Hoja valuada con `item.itemCost` (nunca tuvo movimiento de stock). */
    public const SOURCE_CATALOG = 'catalog';

    /**
     * Costo de la receta de `$itemId` para UNA unidad, desglosado por hoja.
     *
     * @param  bool $waste Aplicar la merma planificada (`item.itemWaste`) en
     *         cada nivel. `false` da el costo teórico sin merma — lo pide
     *         `getProductionCOGS($id, false)`, nadie más.
     * @return array{
     *   total: float,
     *   lines: list<array{itemId:string,qty:float,unitCost:float,source:string,lineCost:float,depth:int,rootChildId:string}>,
     *   byChild: array<string,float>
     * } `byChild` agrupa el costo por ingrediente DIRECTO del padre (lo que
     *   necesita la ficha para mostrar una cifra por fila con una sola
     *   explosión). `lines` está ordenado por recorrido de la receta.
     */
    public static function cost(
        mixed $itemId,
        mixed $companyId,
        mixed $outletId,
        bool $waste = true,
    ): array {
        $empty = ['total' => 0.0, 'lines' => [], 'byChild' => []];

        $outletId = self::requireOutlet($outletId);

        $leaves = Inventory::explodeRecipeDetailed($itemId, $companyId, 1.0, $waste);
        if ($leaves === []) {
            return $empty;
        }

        $costs = self::unitCosts(array_column($leaves, 'itemId'), $companyId, $outletId);

        $total   = 0.0;
        $lines   = [];
        $byChild = [];

        foreach ($leaves as $leaf) {
            $id       = $leaf['itemId'];
            $valued   = $costs[$id] ?? ['cost' => 0.0, 'source' => self::SOURCE_CATALOG];
            $lineCost = $valued['cost'] * $leaf['qty'];

            $total += $lineCost;
            $byChild[$leaf['rootChildId']] = ($byChild[$leaf['rootChildId']] ?? 0.0) + $lineCost;

            $lines[] = [
                'itemId'      => $id,
                'qty'         => $leaf['qty'],
                'unitCost'    => $valued['cost'],
                'source'      => $valued['source'],
                'lineCost'    => $lineCost,
                'depth'       => $leaf['depth'],
                'rootChildId' => $leaf['rootChildId'],
            ];
        }

        return ['total' => $total, 'lines' => $lines, 'byChild' => $byChild];
    }

    /**
     * Solo el número: costo de la receta para UNA unidad del padre.
     *
     * Es lo que consumen los wrappers de COGS (`getProductionCOGS`,
     * `getComboCOGS`) — no necesitan el desglose.
     */
    public static function total(
        mixed $itemId,
        mixed $companyId,
        mixed $outletId,
        bool $waste = true,
    ): float {
        return self::cost($itemId, $companyId, $outletId, $waste)['total'];
    }

    /**
     * Valuación de N ítems en UNA sucursal, en dos queries (no N×2).
     *
     * La regla de valuación completa vive acá y en ningún otro lado: promedio
     * ponderado del ledger si hay movimiento (> 0), costo de catálogo si no.
     * `ProductionService::complete()` la consume para costear los insumos que
     * está descontando, así que la orden de producción y la venta del mismo
     * ítem valúan idéntico.
     *
     * @param  list<string> $itemIds Se deduplica internamente.
     * @return array<string,array{cost:float,source:string}> Solo los ids
     *         encontrados; un id inexistente simplemente no aparece.
     */
    public static function unitCosts(array $itemIds, mixed $companyId, mixed $outletId): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id) => (string) $id,
            $itemIds
        ), static fn (string $id) => $id !== '')));

        if ($ids === []) {
            return [];
        }

        $outletId = self::requireOutlet($outletId);

        // Promedio ponderado vigente por ítem en ESA sucursal. Misma regla de
        // recencia que `Inventory::getItemStock()` (stockDate DESC, stockId
        // solo desempata: los UUID de PG son v4 random, no ordenables por
        // tiempo), resuelta para todos los ítems de una con DISTINCT ON.
        //
        // `companyId` en el WHERE no es redundante con el filtro de `item` de
        // abajo: los itemIds entran por parámetro desde una explosión de
        // receta, y sin este filtro una fila de `stock` de OTRO tenant que
        // compartiera itemId decidiría el costo. Aislamiento multi-tenant se
        // filtra en CADA tabla que se toca, no en una sola del conjunto.
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $avg = [];
        $rs  = ncmExecute(
            'SELECT DISTINCT ON (itemId) itemId, stockOnHandCOGS
               FROM stock
              WHERE itemId IN (' . $ph . ') AND outletId = ? AND companyId = ?
              ORDER BY itemId, stockDate DESC, stockId DESC',
            array_merge($ids, [$outletId, $companyId]),
            false,
            true
        );
        if ($rs) {
            while (!$rs->EOF) {
                $f   = $rs->fields;
                $val = $f['stockOnHandCOGS'] ?? null;
                if (is_numeric($val) && (float) $val > 0) {
                    $avg[(string) $f['itemId']] = (float) $val;
                }
                $rs->MoveNext();
            }
            $rs->Close();
        }

        // Costo de catálogo (fallback). Scoped por company: un itemId de otro
        // tenant no debe aportar su costo ni existir para este cálculo.
        $catalog = [];
        $rs = ncmExecute(
            'SELECT itemId, itemCost FROM item WHERE itemId IN (' . $ph . ') AND companyId = ?',
            array_merge($ids, [$companyId]),
            false,
            true
        );
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $catalog[(string) $f['itemId']] = (float) ($f['itemCost'] ?? 0);
                $rs->MoveNext();
            }
            $rs->Close();
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($avg[$id])) {
                $out[$id] = ['cost' => $avg[$id], 'source' => self::SOURCE_AVG];
                continue;
            }
            if (array_key_exists($id, $catalog)) {
                $out[$id] = ['cost' => $catalog[$id], 'source' => self::SOURCE_CATALOG];
            }
        }

        return $out;
    }

    /** Azúcar de `unitCosts()` para un solo ítem. */
    public static function unitCost(string $itemId, mixed $companyId, mixed $outletId): array
    {
        return self::unitCosts([$itemId], $companyId, $outletId)[$itemId]
            ?? ['cost' => 0.0, 'source' => self::SOURCE_CATALOG];
    }

    /**
     * La sucursal es obligatoria — ver punto 3 del docblock de la clase.
     *
     * Falla fuerte en vez de caer en `OUTLET_ID`: un costeo con la sucursal
     * equivocada no se nota (devuelve un número plausible) y termina impreso
     * en un reporte de margen. Los wrappers legacy resuelven su propio default
     * ANTES de llamar acá.
     */
    private static function requireOutlet(mixed $outletId): string
    {
        $outletId = is_scalar($outletId) ? trim((string) $outletId) : '';
        if ($outletId === '') {
            throw new \InvalidArgumentException(
                'RecipeCosting: falta outletId — el costo de un insumo es por sucursal y no se infiere.'
            );
        }
        return $outletId;
    }
}
