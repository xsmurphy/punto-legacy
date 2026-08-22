<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Stock e inventario del POS.
 *
 * Reemplaza las funciones globales (Slice 13 del plan PSR-4):
 *   - getCompoundsArray($itemId, $cache)              → Inventory::getCompoundsArray(...)
 *   - displayableCompounds($id)                       → Inventory::displayableCompounds($id)
 *   - getProductionCapacity($compounds, $inv, $waste) → Inventory::getProductionCapacity(...)
 *   - getProductionCOGS($itemId, $wasted)             → Inventory::getProductionCOGS(...)
 *   - getComboCOGS($parent)                           → Inventory::getComboCOGS($parent)
 *   - getItemStock($itemId, $outlet, $inLocation)     → Inventory::getItemStock(...)
 *   - getItemMainStock($itemId, $outletId)            → Inventory::getItemMainStock(...)
 *   - getAllItemStock($outlet, $all)                   → Inventory::getAllItemStock(...)
 *   - manageStock($ops)                               → Inventory::manageStock($ops)
 *   - getAllWasteValue($id, $cache)                   → Inventory::getAllWasteValue(...)
 *   - getNeedWithWaste($need, $wasteP)                → Inventory::getNeedWithWaste(...)
 *
 * CRÍTICO: manageStock es el god node del inventario (27 callers). Cualquier
 * delta aquí afecta al money path. Semántica preservada VERBATIM.
 */
final class Inventory
{
    /**
     * Acumulador de `realtimePublish` dentro de `manageStock()` — ver
     * comentario en el bloque que lo llena, más abajo. Un solo evento `item`
     * por request aunque haya N movimientos, con los N itemIds tocados (no
     * solo dedup: batching real — context/15-realtime-sync-plan.md §Modelo
     * quirúrgico, 2026-08-16). `static`: se resetea entre requests (SAPI
     * clásica, shared-nothing — `php -S`/FPM, no hay proceso persistente
     * tipo Swoole en este stack), así que no hay leak entre tenants.
     */
    private static array $stockEventItemIds = [];
    private static ?string $stockEventCompanyId = null;
    private static bool $stockEventShutdownRegistered = false;

    /**
     * Publica el evento `item` acumulado por `manageStock()` en este
     * request, con TODOS los itemIds tocados (venta de 30 líneas → 1 evento
     * con 30 ids, no 30 eventos). Se registra como shutdown function para
     * correr al final del request sin importar cuál caller hizo el último
     * movimiento — la alternativa (que cada caller de alto nivel se acuerde
     * de "cerrar" el batch) es exactamente el patrón que se eliminó al
     * centralizar el publish acá (ver hardening 2026-08-15).
     *
     * Público, y drena+resetea el acumulador ANTES de publicar (no después):
     * si `realtimePublish` tirara algo raro, el batch no queda "trabado"
     * esperando un flush que en producción no va a volver a llamarse (la
     * shutdown function corre una sola vez por request). El reset también
     * es lo que permite al arnés de verificación invocarlo explícito varias
     * veces en el mismo proceso para simular requests sucesivos sin
     * esperar al shutdown real de PHP (ver `verify_realtime.php`) — sin
     * ids nuevos acumulados, una segunda llamada no-opea sola (`empty($ids)`).
     *
     * Sin umbral de cantidad: el batch entero viaja en `ids` y el BFF del
     * POS lo resuelve con UNA sola query `itemId IN (...)` (`/v1/items
     * ?resource=bulk-get`), así que no hace falta degradar a "recargar todo
     * el catálogo" ni siquiera con miles de ids (decisión del owner
     * 2026-08-16 — reemplaza un umbral que se había planteado antes).
     */
    public static function flushRealtimeStockEvents(): void
    {
        $ids       = array_keys(self::$stockEventItemIds);
        $companyId = self::$stockEventCompanyId;

        self::$stockEventItemIds            = [];
        self::$stockEventCompanyId          = null;
        self::$stockEventShutdownRegistered = false;

        if (empty($ids) || !$companyId) return;

        try {
            realtimePublish('item', 'update', null, 'all', $companyId, $ids);
        } catch (\Throwable $e) {
            // Ignorar — nunca interrumpir el movimiento de stock por esto.
        }
    }

    /**
     * Compuestos de un ítem (receta/ingredientes). Usa $getAssoc=true.
     * Equivalente legacy: `getCompoundsArray($itemId, $cache)`.
     *
     * Producción F0 (context/23): fuente canónica pasó de `toCompound` a
     * `item_compound` (mig 19/75). Los callers (SaleService, TransactionService,
     * functions.php, getProductionCapacity/COGS/displayableCompounds acá mismo)
     * esperan el shape plano legacy — se alias-ea childItemId→compoundId y
     * quantity→toCompoundQty para no tocar ~20 call-sites. `toCompoundPreselected`
     * no existe en item_compound (esa semántica de combo-picker quedó en
     * toCompound y no se migró — ver mig 75) así que siempre viaja NULL.
     *
     * CRÍTICO — la PRIMERA columna del SELECT debe ser ÚNICA por fila:
     * $getAssoc=true keyea el resultado por la primera columna
     * (DB::GetAssoc → $key = reset($row)) y los duplicados se PISAN. El
     * SELECT * legacy tenía toCompoundId (PK) primero; acá va el PK de
     * item_compound (compoundId) aliased a toCompoundId. Con una columna
     * no-única primero (ej. parentItemId), una receta multi-ingrediente
     * colapsaría a UNA fila y la venta consumiría solo un ingrediente.
     */
    public static function getCompoundsArray(mixed $itemId, mixed $cache = false): mixed
    {
        return ncmExecute(
            'SELECT ic.compoundId AS toCompoundId, ic.parentItemId AS itemId,
                    ic.childItemId AS compoundId, ic.quantity AS toCompoundQty,
                    ic.sort AS toCompoundOrder, NULL::uuid AS toCompoundPreselected
               FROM item_compound ic WHERE ic.parentItemId = ? ORDER BY ic.sort LIMIT 1000',
            [$itemId],
            $cache,
            true,
            true
        );
    }

    /**
     * Explota la receta de un ítem hasta las hojas que REALMENTE mueven stock.
     *
     * El problema que resuelve: la receta puede tener varios niveles. Un combo
     * está hecho de rolls y cada roll de insumos. Recorrer un solo nivel deja
     * sin descontar todo lo que cuelga de un hijo que no lleva stock propio:
     * en "Combo 30 Piezas" (combo → 3 rolls → insumos) solo se descontaba el
     * único roll que trackea inventario, y los insumos de los otros dos no se
     * tocaban nunca. Lo mismo en producción, con recetas que usan
     * sub-preparaciones.
     *
     * La regla de corte es la misma que `saleExplodesRecipe()`, aplicada nivel
     * a nivel:
     *   - el hijo lleva stock propio (`itemTrackInventory`) o es de producción
     *     previa → se consume ESE hijo y se corta. Su receta ya se descontó
     *     cuando se produjo; volver a bajar la descontaría dos veces.
     *   - el hijo no lleva stock propio → no hay qué descontar de él, se baja
     *     a su receta.
     *
     * La merma planificada (`item.itemWaste`) se aplica en CADA nivel: si el
     * roll pierde 10% y su insumo otro 5%, las dos mermas se acumulan, que es
     * lo que pasa en la cocina.
     *
     * Las cantidades se MULTIPLICAN nivel a nivel (owner, 2026-08-11): 2
     * combos × 3 unidades del hijo = 6, y si ese hijo es de producción directa
     * sus insumos se multiplican por esas 6, y así hasta las hojas. La
     * profundidad NO se limita con un tope fijo: el guard de ciclos ya
     * garantiza que la recursión termina (un ítem no puede repetirse en la
     * misma rama), y un tope cortaría sin descontar una receta legítima más
     * profunda — la misma clase de bug que esta función vino a arreglar, pero
     * silencioso.
     *
     * La recursión en sí vive en `explodeRecipeDetailed()` — esta función es
     * el agregador que aplana sus hojas de STOCK a `itemId → cantidad`. Una
     * sola implementación de las reglas de corte/merma/ciclos, compartida con
     * el costeo (`RecipeCosting`): si divergieran, el COGS de una venta dejaría
     * de valuar exactamente lo que esa venta descontó.
     *
     * @param  array<string,bool> $visitados Guard de ciclos (A→B→A): una receta
     *         circular colgaría el proceso. Interno de la recursión.
     * @return array<string,float> itemId → cantidad a mover
     */
    public static function explodeRecipe(
        mixed $itemId,
        mixed $companyId,
        float $units,
        array $visitados = [],
    ): array {
        $out = [];

        foreach (self::explodeRecipeDetailed($itemId, $companyId, $units, true, $visitados) as $leaf) {
            // Solo las hojas que REALMENTE mueven stock. Las hojas de costeo
            // (`stockLeaf=false`: insumo sin control de inventario, o rama
            // cortada por el guard de ciclos) no tienen ledger que descontar —
            // `manageStock()` sería un no-op indistinguible de un error.
            if ($leaf['stockLeaf'] !== true) {
                continue;
            }
            $out[$leaf['itemId']] = ($out[$leaf['itemId']] ?? 0) + $leaf['qty'];
        }

        return $out;
    }

    /**
     * La MISMA explosión que `explodeRecipe()`, sin aplanar: una entrada por
     * hoja alcanzada, con su profundidad y de qué ingrediente directo del
     * padre cuelga.
     *
     * Existe porque el costeo necesita tres cosas que el mapa plano
     * `itemId → cantidad` ya perdió:
     *
     *   1. **Las hojas que NO mueven stock.** Un insumo sin control de
     *      inventario (agua, sal, condimentos) no genera movimiento pero SÍ
     *      cuesta plata, y su costo entra a la receta (misma regla que
     *      `ProductionService::complete()` documenta desde F1). `explodeRecipe()`
     *      lo descartaba en silencio: `saleExplodesRecipe()` da true (no lleva
     *      stock propio) y como no tiene receta, la rama devolvía `[]`. Acá
     *      esa hoja sale con `stockLeaf=false` y el costeo la valúa; el
     *      consumidor de stock la filtra.
     *   2. **`depth`**, para mostrar/auditar de qué nivel salió cada costo.
     *   3. **`rootChildId`**, el ingrediente DIRECTO del padre del que
     *      desciende la hoja — lo que permite costear línea por línea la ficha
     *      de la receta con UNA sola explosión en vez de una por ingrediente.
     *
     * Reglas de corte, merma por nivel, multiplicación de cantidades y guard
     * de ciclos son EXACTAMENTE las de `explodeRecipe()` (que ahora es un
     * agregador sobre esta función): una sola recursión, una sola definición
     * de "qué consume esta receta". Ver el docblock de `explodeRecipe()`.
     *
     * `$waste=false` desactiva la merma planificada en TODOS los niveles —
     * lo usa `getProductionCOGS($itemId, false)`, el único caller que quiere
     * el costo teórico sin merma. El stock SIEMPRE explota con merma.
     *
     * @param  array<string,bool> $visitados Guard de ciclos (A→B→A). Interno.
     * @param  int                $depth     Nivel actual (1 = hijo directo). Interno.
     * @param  string|null        $rootChildId Ingrediente directo del que desciende. Interno.
     * @return list<array{itemId:string,qty:float,depth:int,rootChildId:string,stockLeaf:bool}>
     */
    public static function explodeRecipeDetailed(
        mixed $itemId,
        mixed $companyId,
        float $units,
        bool $waste = true,
        array $visitados = [],
        int $depth = 1,
        ?string $rootChildId = null,
    ): array {
        if (!validity($itemId) || $units <= 0) {
            return [];
        }

        // Ciclo: la receta se referencia a sí misma en algún nivel. Se corta y
        // se deja rastro — es un dato mal cargado, no una condición normal.
        if (isset($visitados[$itemId])) {
            error_log('Inventory::explodeRecipe: receta circular detectada en item ' . $itemId);
            return [];
        }
        $visitados[$itemId] = true;

        $compounds = self::getCompoundsArray($itemId);
        if (!is_array($compounds) || $compounds === []) {
            return [];
        }

        $allWaste = $waste ? self::getAllWasteValue() : [];
        $out      = [];

        foreach ($compounds as $comp) {
            $childId = $comp['compoundId'] ?? null;
            if (!$childId) {
                continue;
            }

            $need = (float) ($comp['toCompoundQty'] ?? 0) * $units;
            if ($need <= 0) {
                continue;
            }

            $wasteP = $allWaste[$childId] ?? '';
            if ($wasteP > 0) {
                $need = (float) self::getNeedWithWaste($need, $wasteP);
            }

            $childId = (string) $childId;
            $root    = $rootChildId ?? $childId;

            if (self::saleExplodesRecipe($childId, $companyId)) {
                // No lleva stock propio: lo que se consume está más abajo.
                $sub = self::explodeRecipeDetailed($childId, $companyId, $need, $waste, $visitados, $depth + 1, $root);

                if ($sub === []) {
                    // Fondo de la rama sin receta debajo: es un insumo que no
                    // lleva ledger (o una rama cortada por ciclo). No hay stock
                    // que mover, pero es un costo real de la receta — se emite
                    // como hoja de COSTEO, no de stock.
                    $out[] = [
                        'itemId'      => $childId,
                        'qty'         => $need,
                        'depth'       => $depth,
                        'rootChildId' => $root,
                        'stockLeaf'   => false,
                    ];
                    continue;
                }

                foreach ($sub as $leaf) {
                    $out[] = $leaf;
                }
                continue;
            }

            // Lleva stock propio (o es producción previa): se consume acá.
            $out[] = [
                'itemId'      => $childId,
                'qty'         => $need,
                'depth'       => $depth,
                'rootChildId' => $root,
                'stockLeaf'   => true,
            ];
        }

        return $out;
    }

    /**
     * ¿Vender este ítem debe consumir su receta (descontar los insumos)?
     *
     * Los dos modelos de un ítem con receta son EXCLUYENTES — un ítem lleva
     * su propio stock terminado, o lo arma al vender, nunca las dos cosas:
     *
     *   - **Producción Previa** (`itemProduction IS TRUE`): la orden de
     *     producción ya descontó los insumos y sumó el terminado. La venta
     *     descuenta SOLO el terminado. Explotar la receta acá los descuenta
     *     por segunda vez — reporte del tester 2026-08-04.
     *   - **Producción Directa** (`itemTrackInventory IS FALSE` + receta): no
     *     lleva stock propio; se arma al vender. La venta SÍ explota la receta.
     *
     * El discriminante canónico es el del dominio (`getItemTypeName()`,
     * functions.php:811-818), leído de la BD. NO se infiere del payload del
     * carrito: `$saleDetail[]['type']` es opcional y el POS no lo manda
     * (create-sale.ts arma SaleItem sin ese campo), así que un guard basado
     * en él nunca corta. Vive acá y no en cada caller porque la venta
     * (SaleService) y la anulación (TransactionService, que repone lo que la
     * venta descontó) tienen que coincidir — si divergen, anular una venta
     * repone insumos que nunca se consumieron.
     *
     * @return bool true = explotar receta; false = el ítem lleva stock propio.
     */
    public static function saleExplodesRecipe(mixed $itemId, mixed $companyId): bool
    {
        // El predicado se evalúa EN SQL y vuelve como int, no como boolean:
        // itemProduction / itemTrackInventory son BOOLEAN en PG (mig 15) y su
        // representación en PHP depende del driver ('t'/'f', true/false, 1/'').
        // Un `(float) 't'` da 0.0 y daría vuelta el resultado — un terminado
        // de producción previa volvería a consumir insumos, que es justo el
        // bug que esto arregla. `IS NOT TRUE` además cubre el NULL.
        //
        // Se usan los flags y no `itemKind` a propósito: mig 15 documenta que
        // el backfill de kind NO pudo inferir `produccion_directa` y le asignó
        // el tipo más cercano, así que hay filas con kind impreciso. Los flags
        // son los que el POS y el panel legacy mantienen al día.
        $row = ncmExecute(
            'SELECT CASE WHEN itemProduction IS NOT TRUE
                          AND itemTrackInventory IS NOT TRUE
                         THEN 1 ELSE 0 END AS explodes
               FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$row) {
            // Ítem ilegible (borrado / otro tenant): conservador — no tocar
            // insumos. Descontar de más es irreversible sin ajuste manual.
            return false;
        }

        return ((int) ($row['explodes'] ?? 0)) === 1;
    }

    /**
     * Compuestos en formato displayable para el front [{id, units, select}].
     * Equivalente legacy: `displayableCompounds($id)`.
     */
    public static function displayableCompounds(mixed $id): array
    {
        $out       = [];
        $compounds = self::getCompoundsArray($id);

        if ($compounds) {
            foreach ($compounds as $key => $value) {
                $out[] = [
                    'id'     => enc($value['compoundId']),
                    'units'  => $value['toCompoundQty'],
                    'select' => $value['toCompoundPreselected'],
                ];
            }
        }

        return $out;
    }

    /**
     * Capacidad de producción: unidades que se pueden fabricar dado el inventario.
     * Retorna el mínimo de unidades posibles por compuesto.
     * Equivalente legacy: `getProductionCapacity($compounds, $inventory, $waste)`.
     */
    public static function getProductionCapacity(mixed $compounds, mixed $inventory, mixed $waste = false): int|float
    {
        if (!$waste) {
            $waste = [];
        }

        if (validity($compounds, 'array') && $inventory) {
            $eachAmount = [];

            foreach ($compounds as $val) {
                $need  = $val['toCompoundQty'];
                $wasteP = 0;
                if (array_key_exists($val['compoundId'], $waste)) {
                    $wasteP = $waste[$val['compoundId']];
                }

                if ($wasteP > 0) {
                    $need = self::getNeedWithWaste($need, $wasteP);
                }

                if ($need > 0) {
                    $have = 0;
                    if (array_key_exists($val['compoundId'], $inventory)) {
                        $have = $inventory[$val['compoundId']]['onHand'];
                    }

                    $divi         = divider($need, $have);
                    $eachAmount[] = round($divi, 3);
                }
            }

            return $eachAmount ? min($eachAmount) : 0;
        }

        return 0;
    }

    /**
     * Costo de producción (COGS) de un ítem a partir de su receta, por UNIDAD.
     *
     * @deprecated 2026-08-22 — wrapper de `RecipeCosting::total()`, que es la
     *   única fórmula de costo de receta del sistema. Los callers nuevos deben
     *   llamar a `RecipeCosting` directo y pasarle la sucursal REAL de la
     *   operación; esta función sigue existiendo por sus ~8 callers legacy.
     *
     * Qué cambió respecto de la implementación vieja (los tres eran bugs, ver
     * `RecipeCosting` y el reporte del tester "Actualización 21" #1):
     *   - era de UN nivel: una sub-preparación dentro de la receta no bajaba a
     *     sus insumos, así que su costo real no entraba;
     *   - no tenía fallback a `item.itemCost`: un insumo sin movimiento de
     *     stock valía 0 y abarataba la receta en silencio;
     *   - resolvía la sucursal con `OUTLET_ID` (la de la SESIÓN) incluso
     *     costeando una venta de otra sucursal.
     *
     * `$outlet`/`$companyId` son opcionales SOLO para no romper los callers
     * legacy; el default a `OUTLET_ID`/`COMPANY_ID` vive acá, no en
     * `RecipeCosting` (que los exige explícitos).
     */
    public static function getProductionCOGS(
        mixed $itemId,
        bool $wasted = true,
        mixed $outlet = false,
        mixed $companyId = null,
    ): int|float {
        return RecipeCosting::total(
            $itemId,
            $companyId ?: COMPANY_ID,
            $outlet ?: OUTLET_ID,
            $wasted
        );
    }

    /**
     * COGS de un combo, por UNIDAD del combo.
     *
     * @deprecated 2026-08-22 — wrapper de `RecipeCosting::total()`. Ver la nota
     *   en `getProductionCOGS()`.
     *
     * El fix F0 (context/23) ya había corregido lo peor (usaba `itemPrice`, el
     * precio de VENTA del componente, como si fuera costo). Lo que quedaba:
     * era de UN nivel, así que un combo cuyo componente es de producción
     * directa se costeaba con el `itemCost` de catálogo de ESE componente en
     * vez de con sus insumos reales — pese a que la venta del combo sí explota
     * la receta hasta las hojas (`explodeRecipe`, combos recursivos). Ahora el
     * COGS valúa exactamente las mismas hojas que el movimiento de stock
     * descontó, merma incluida.
     */
    public static function getComboCOGS(
        mixed $parent,
        mixed $outlet = false,
        mixed $companyId = null,
    ): int|float {
        return RecipeCosting::total(
            $parent,
            $companyId ?: COMPANY_ID,
            $outlet ?: OUTLET_ID,
            true
        );
    }

    /**
     * Stock de un ítem en una sucursal (o en una ubicación específica).
     * Equivalente legacy: `getItemStock($itemId, $outlet, $inLocation)`.
     */
    public static function getItemStock(mixed $itemId, mixed $outlet = false, mixed $inLocation = false): mixed
    {
        if (!validity($itemId)) {
            return false;
        }

        if ($inLocation) {
            $location = ncmExecute(
                'SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',
                [$itemId, $inLocation]
            );
            return ($location) ? $location['toLocationCount'] : 0;
        }

        $outletId = $outlet ?: OUTLET_ID;

        return ncmExecute(
            // "Stock actual" = la fila más reciente. stockId es UUID v4 random en PG
            // (no ordenable por tiempo) → ordenar por stockId daría una fila
            // arbitraria. La recencia la da stockDate; stockId DESC sólo desempata.
            'SELECT * FROM stock WHERE itemId = ? AND outletId = ? ORDER BY stockDate DESC, stockId DESC LIMIT 1',
            [$itemId, $outletId]
        );
    }

    /**
     * Saldo real de un ítem en una sucursal: la SUMA de sus movimientos.
     *
     * `stock.stockCount` guarda el movimiento con signo ('-0.080', '50.000'),
     * así que la suma ES el saldo, por definición y sin depender del orden en
     * que se hayan insertado las filas. `stock.stockOnHand` es un saldo
     * ACUMULADO cacheado al momento del INSERT: sirve para leer rápido y para
     * mostrar el historial, pero se desincroniza si alguien inserta un
     * movimiento con fecha anterior a la última fila (compra cargada con fecha
     * de ayer), porque nadie recalcula las filas que quedaron después.
     *
     * Por eso esta función es la fuente de verdad para CALCULAR, y
     * `getItemStock()` sigue sirviendo para LEER el último saldo cacheado.
     */
    public static function onHand(mixed $itemId, mixed $outletId): float
    {
        if (!validity($itemId) || !$outletId) {
            return 0.0;
        }

        $row = ncmExecute(
            'SELECT COALESCE(SUM(stockCount), 0) AS onhand
               FROM stock WHERE itemId = ? AND outletId = ?',
            [$itemId, $outletId]
        );

        return $row ? (float) ($row['onhand'] ?? 0) : 0.0;
    }

    /**
     * Recalcula el historial cacheado de un ítem en una sucursal:
     * `stockOnHand` de todas las filas, `stockOnHandCOGS` de todas, y
     * `stockCOGS` de los EGRESOS (que salen al promedio vigente).
     *
     * Hace falta porque `stockOnHand`/`stockOnHandCOGS` son acumulados
     * calculados al INSERT. Un movimiento con fecha anterior a la última fila
     * —una compra cargada con fecha de ayer— se mete en el medio del historial
     * y todas las filas posteriores quedan con su acumulado viejo. Recalcular
     * la cadena es la única forma de que el historial cierre.
     *
     * El costo de los INGRESOS (`stockCOGS`) es dato de entrada y no se toca:
     * es lo que se pagó. Todo lo demás se deriva de ahí.
     *
     * Mismas reglas que `manageStock()`, a propósito — si una cambia, la otra
     * también.
     */
    public static function rebuildLedger(mixed $itemId, mixed $outletId): void
    {
        global $db;

        if (!validity($itemId) || !$outletId) {
            return;
        }

        $rs = ncmExecute(
            'SELECT stockId, stockCount, stockCOGS, stockOnHand, stockOnHandCOGS
               FROM stock
              WHERE itemId = ? AND outletId = ?
              ORDER BY stockDate ASC, stockId ASC',
            [$itemId, $outletId],
            false,
            true // forceObj → recordset
        );
        if (!$rs || !is_object($rs)) {
            return;
        }

        $qty = 0.0;
        $avg = 0.0;

        while (!$rs->EOF) {
            $f     = $rs->fields;
            $id    = (string) ($f['stockId'] ?? $f['stockid'] ?? '');
            $delta = (float) ($f['stockCount'] ?? $f['stockcount'] ?? 0);
            $cost  = (float) ($f['stockCOGS'] ?? $f['stockcogs'] ?? 0);

            if ($delta > 0) {
                // Ingreso: recalcula el promedio con su propio costo. Un saldo
                // negativo no aporta base (no se compró a ningún precio).
                $base  = $qty > 0 ? $qty : 0.0;
                $total = $base + $delta;
                $avg   = $total > 0 ? ((($avg * $base) + ($cost * $delta)) / $total) : $cost;
            } else {
                // Egreso: sale al promedio vigente y no lo altera.
                $cost = $avg;
            }

            $qty += $delta;

            $prevOnHand = (float) ($f['stockOnHand']     ?? $f['stockonhand']     ?? 0);
            $prevAvg    = (float) ($f['stockOnHandCOGS'] ?? $f['stockonhandcogs'] ?? 0);
            $prevCost   = (float) ($f['stockCOGS']       ?? $f['stockcogs']       ?? 0);

            // Solo escribe lo que cambió: en el caso normal (movimiento al
            // final del historial) no hay ninguna fila que tocar.
            if (abs($prevOnHand - $qty) > 1e-9
                || abs($prevAvg - $avg) > 1e-9
                || ($delta <= 0 && abs($prevCost - $cost) > 1e-9)) {
                $db->Execute(
                    'UPDATE stock SET stockOnHand = ?, stockOnHandCOGS = ?, stockCOGS = ?
                      WHERE stockId = ?',
                    [$qty, $avg, $cost, $id]
                );
            }

            $rs->MoveNext();
        }
        $rs->Close();
    }

    /**
     * Stock principal de un ítem (descontando ubicaciones de depósito).
     * Equivalente legacy: `getItemMainStock($itemId, $outletId)`.
     */
    public static function getItemMainStock(mixed $itemId, mixed $outletId): mixed
    {
        $inventory = self::getItemStock($itemId, $outletId);
        $count     = formatQty($inventory['stockOnHand']);

        $depo = ncmExecute(
            "SELECT * FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC",
            [$outletId],
            false,
            true
        );

        if ($depo) {
            $dTotal = 0;
            while (!$depo->EOF) {
                $dCount   = 0;
                $depCount = ncmExecute(
                    'SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',
                    [$depo->fields['taxonomyId'], $itemId]
                );

                if ($depCount) {
                    $dCount = $depCount['toLocationCount'];
                }

                $dTotal += $dCount;
                $count   = $count - $dTotal;

                $depo->MoveNext();
            }
        }

        return $count;
    }

    /**
     * Stock de todos los ítems para una o todas las sucursales.
     * Equivalente legacy: `getAllItemStock($outlet, $all)`.
     */
    public static function getAllItemStock(mixed $outlet = false, bool $all = false): array
    {
        // "Stock actual" = la fila de stock MÁS RECIENTE por item. El legacy usaba
        // max(stockId) (MySQL: PK autoincrement → max = última). En PG stockId es
        // UUID v4 random (DEFAULT gen_random_uuid()), NO ordenable por tiempo, así
        // que max(stockId)/ORDER BY stockId devolverían una fila ARBITRARIA. La
        // recencia real la da stockDate (TIMESTAMPTZ DEFAULT now()); stockId DESC
        // sólo desempata para determinismo. array_agg(...)[1] reemplaza max(uuid)
        // (inexistente en PG) sin cambiar la estructura del JOIN.
        $sql = 'SELECT t1.itemId as itemId, t1.stockOnHand as onHand, t1.stockOnHandCOGS as cogs
                FROM stock t1
                JOIN (
                    SELECT (array_agg(stockId ORDER BY stockDate DESC, stockId DESC))[1] AS stockId
                    FROM stock
                    WHERE outletId = ?
                    GROUP BY itemId
                ) t2 ON t1.stockId = t2.stockId AND t1.outletId = ?';

        if ($all) {
            $allOutletsArray = getAllOutletData();
            $result          = [];
            foreach ($allOutletsArray as $outletKey => $val) {
                $item = ncmExecute($sql, [$outletKey, $outletKey], false, true, true);
                if ($item) {
                    foreach ($item as $itemId => $values) {
                        $result[$itemId]['itemId']   = $values['itemId'];
                        $result[$itemId]['onHand'] += $values['onHand'];
                        $result[$itemId]['cogs']     = $values['cogs'];
                    }
                }
            }
        } else {
            $outlet = iftn($outlet, OUTLET_ID);
            $result = ncmExecute($sql, [$outlet, $outlet], false, true, true);
        }

        return validity($result) ? $result : [];
    }

    /**
     * Registra un movimiento de stock (entrada/salida) con auditoría.
     * CRÍTICO — money path: afecta costeo (COGS) de ventas.
     * Equivalente legacy: `manageStock($ops)`.
     *
     * Quirks preservados verbatim:
     * - $transaction/$supplierId/$locationId usan ?: null (NO iftn) para UUIDs vacíos.
     * - UUID entre comillas simples en SQL concat (§22.5).
     * - CaseInsensitiveArray check vía instanceof ArrayAccess.
     */
    public static function manageStock(array $ops): mixed
    {
        global $db;

        $itemId      = $ops['itemId'];
        $source      = iftn($ops['source'], 'adjustment');
        $count       = $ops['count'];
        $type        = iftn($ops['type'] ?? '', '+');
        $COGS        = array_key_exists('cogs', $ops) ? $ops['cogs'] : '';
        $user        = iftn(array_key_exists('userId', $ops) ? $ops['userId'] : '', USER_ID);
        $transaction = $ops['transactionId'];
        $supplier    = array_key_exists('supplierId', $ops) ? $ops['supplierId'] : '';
        $outlet      = $ops['outletId'];
        $location    = $ops['locationId'];
        $note        = array_key_exists('note', $ops) ? $ops['note'] : '';
        $date        = $ops['date'];
        $company     = iftn(array_key_exists('companyId', $ops) ? $ops['companyId'] : '', COMPANY_ID);

        if (!validity($count) || !$itemId) {
            return false;
        }

        $isStockeable = ncmExecute(
            'SELECT itemTrackInventory FROM item WHERE itemStatus = 1 AND itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, COMPANY_ID]
        );

        if (!$isStockeable || $isStockeable['itemTrackInventory'] < 1) {
            return false;
        }

        // El saldo se lee de la MISMA sucursal a la que se escribe el
        // movimiento. Antes esto era `getItemStock($itemId)` sin sucursal, que
        // cae en el default `OUTLET_ID` — la sucursal de la SESIÓN, no la de la
        // operación. Una compra cargada desde el panel (sesión en sucursal A)
        // hacia la sucursal B leía el saldo de A: si A no tenía ese ítem, la
        // compra escribía en B un saldo como si B estuviera en cero, pisando lo
        // que B ya tenía.
        $stock    = self::getItemStock($itemId, $outlet);
        $hasStock = ($stock instanceof \ArrayAccess) || is_array($stock);
        $oldACOGS = ($hasStock && isset($stock['stockOnHandCOGS']) && is_numeric($stock['stockOnHandCOGS'])) ? $stock['stockOnHandCOGS'] : 0;

        // El saldo sale de la SUMA de los movimientos, no del `stockOnHand` de
        // la última fila. Encadenar contra el snapshot de la última fila hace
        // que un movimiento con fecha anterior (una compra cargada con fecha de
        // ayer, típico) se pierda: se inserta en el medio del historial y las
        // filas posteriores, que ya tenían su saldo calculado, nunca se
        // recalculan — los +50 de la compra desaparecen del saldo.
        //
        // La suma es independiente del orden de inserción, así que además
        // corrige sola cualquier fila vieja que haya quedado mal.
        $oldStock = self::onHand($itemId, $outlet);

        if (!validity($COGS)) {
            $COGS = ($hasStock && isset($stock['stockCOGS'])) ? $stock['stockCOGS'] : '';
        }

        // ── Costeo: promedio ponderado móvil ────────────────────────────────
        // `stockCOGS`       = costo unitario DE ESTE movimiento.
        // `stockOnHandCOGS` = costo promedio del saldo DESPUÉS del movimiento.
        //
        // Cada ingreso recalcula el promedio con su propio costo; cada egreso
        // sale al promedio vigente y NO lo altera. Los dos dejan su fila con el
        // costo del movimiento y el promedio resultante.
        //
        // No se usa `divider()` acá: `Math::divide` devuelve 0 cuando cualquiera
        // de los dos operandos es <= 0, así que una compra sobre saldo negativo
        // borraba el costo promedio en silencio en vez de recalcularlo.
        $count = (float) $count;

        if ($type == '+') {
            $newOnHand = $oldStock + $count;

            // Saldo negativo o cero no tiene costo que promediar: lo que había
            // "de menos" no se compró a ningún precio. La base del promedio
            // arranca en 0 y el promedio nuevo queda en el costo de esta compra
            // — antes, multiplicar el promedio viejo por un saldo negativo daba
            // un numerador negativo y el resultado terminaba en 0.
            $baseQty = $oldStock > 0 ? $oldStock : 0.0;
            $totalQty = $baseQty + $count;

            $newTotalCOGS = $totalQty > 0
                ? ((($oldACOGS * $baseQty) + ((float) $COGS * $count)) / $totalQty)
                : (float) $COGS;
        } else {
            $newOnHand = $oldStock - $count;
            // El egreso sale al promedio vigente y lo DEJA INTACTO. Antes se
            // reseteaba a 0 al llegar a saldo <= 0, y como el saldo venía mal
            // calculado (ver onHand()), el costo promedio se borraba en casi
            // todos los ítems: 335 de 412 filas quedaron con costo 0 y los
            // márgenes de esas ventas salieron inflados.
            $COGS         = $oldACOGS;
            $newTotalCOGS = $oldACOGS;
        }

        $row['stockSource']     = $source;
        $row['stockNote']       = $note;
        $row['stockCount']      = $type . $count;
        $row['stockCOGS']       = $COGS;
        $row['stockOnHand']     = $newOnHand;
        $row['stockOnHandCOGS'] = $newTotalCOGS;
        $row['itemId']          = $itemId;
        $row['transactionId']   = $transaction ?: null;
        $row['userId']          = $user;
        $row['supplierId']      = $supplier ?: null;
        $row['outletId']        = $outlet;
        $row['locationId']      = $location ?: null;
        $row['companyId']       = $company;

        if ($date) {
            $row['stockDate'] = $date;
        }

        // ¿El movimiento queda en el MEDIO del historial? Pasa siempre que se
        // carga con fecha anterior a la última fila (una compra fechada ayer).
        // Se mira ANTES del INSERT para no compararlo contra sí mismo.
        $lastDate = ($hasStock && isset($stock['stockDate'])) ? $stock['stockDate'] : null;
        $isBackdated = $date && $lastDate && strtotime((string) $date) < strtotime((string) $lastDate);

        $insert = $db->AutoExecute('stock', $row, 'INSERT');

        if ($insert !== true) {
            return false;
        }

        // Insertado en el medio: las filas posteriores quedaron con su saldo y
        // su costo promedio viejos, así que hay que rehacer la cadena. En el
        // caso normal (movimiento al final) esto no corre.
        if ($isBackdated) {
            self::rebuildLedger($itemId, $outlet);
        }

        // PG: UUID entre comillas simples (§22.5).
        updateRowLastUpdate('item', "itemId = '" . $itemId . "'");

        // Realtime best-effort — ÚNICO lugar que publica invalidación de stock.
        // manageStock() es la puerta por la que pasa TODO movimiento (venta,
        // compra, anulación, devolución, producción, merma, ajuste, transfer,
        // conteo, variantes — 27 callers). Publicar acá, no en cada caller,
        // es lo que hace estructuralmente imposible que un caller nuevo se
        // "olvide" de avisar (regla del owner, ver context/15).
        //
        // Batching por request, no dedup ciego: una venta con 30 líneas
        // dispara 30 movimientos, pero el POS necesita saber CUÁLES 30 ítems
        // cambiaron para actualizarlos quirúrgicamente sin bajar el catálogo
        // entero (5000+ productos en tenants grandes — invalidar el bootstrap
        // en cada venta de cualquier caja sería peor que el polling que esto
        // reemplazó). Se acumulan los itemIds tocados en este request y se
        // publica UN evento con todos ellos al final (`flushRealtimeStockEvents`,
        // shutdown function) — nunca uno por movimiento.
        self::$stockEventItemIds[$itemId] = true; // set — dedup automático por key
        self::$stockEventCompanyId ??= $company;   // primer caller del request gana
        if (!self::$stockEventShutdownRegistered) {
            self::$stockEventShutdownRegistered = true;
            register_shutdown_function([self::class, 'flushRealtimeStockEvents']);
        }

        if ($location) {
            $isLocation = ncmExecute(
                'SELECT toLocationId FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',
                [$location, $itemId]
            );
            if ($isLocation) {
                $db->Execute(
                    "UPDATE toLocation SET toLocationCount = toLocationCount" . $type . $count . " WHERE toLocationId = '" . $isLocation['toLocationId'] . "'"
                );
            } else {
                $db->AutoExecute('toLocation', [
                    'locationId'       => $location,
                    'toLocationCount'  => $type . $count,
                    'itemId'           => $itemId,
                ], 'INSERT');
            }
        }

        try {
            $userName     = getValue('contact',  'contactName',  "WHERE contactId = '" . USER_ID . "'");
            // REGISTER_ID es '' en contexto panel (compras/ajustes sin caja). Un
            // SELECT con registerId = '' tira "invalid input syntax for type uuid"
            // y, al correr DENTRO de la TX de la compra, la ABORTA (la transacción
            // PG queda envenenada aunque PHP no lance). Solo resolvemos el nombre
            // si hay una caja real.
            $registerName = (defined('REGISTER_ID') && REGISTER_ID !== '')
                ? getValue('register', 'registerName', "WHERE registerId = '" . REGISTER_ID . "'")
                : '';
            $companyName  = defined('COMPANY_NAME') ? COMPANY_NAME : '';
            $outletName   = getCurrentOutletName(OUTLET_ID);
            $itemName     = getItemName($itemId);

            $auditoriaData = [
                'date'      => $date,
                'user'      => $userName,
                'module'    => 'STOCK',
                'origin'    => 'CAJA',
                'company_id' => COMPANY_ID,
                'data'      => [
                    'action'        => "El usuario $userName ajustó el item $itemName desde la caja " . $registerName,
                    'userId'        => USER_ID,
                    'userName'      => $userName,
                    'itemId'        => $itemId,
                    'itemName'      => $itemName,
                    'operationData' => $row,
                    'registerId'    => REGISTER_ID,
                    'registerName'  => $registerName,
                    'companyID'     => COMPANY_ID,
                    'companyName'   => $companyName,
                    'outletId'      => OUTLET_ID,
                    'outletName'    => $outletName,
                    'timestamp'     => $ops['timestamp'],
                ],
            ];

            sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
        } catch (\Throwable $th) {
            error_log("Error al enviar registro de auditoría de ajuste de stock: \n", 3, './error_log');
            error_log(print_r($th, true), 3, './error_log');
            error_log("data stock: \n", 3, './error_log');
            error_log(print_r($row, true), 3, './error_log');
        }

        return $row;
    }

    /**
     * Porcentaje de merma (waste) por itemId. Con $id retorna solo ese ítem.
     * Equivalente legacy: `getAllWasteValue($id, $cache)`.
     */
    public static function getAllWasteValue(mixed $id = false, mixed $cache = false): array
    {
        // `itemWaste` NO es una columna: vive en el JSONB `data` (demote de
        // item, mismo patrón que outlet en la mig 14). Leerlo como columna
        // tiraba 42703 "column itemwaste does not exist", y como esta función
        // corre DENTRO de la transacción de la venta, el error abortaba la TX
        // entera: todo lo que seguía fallaba con 25P02 ("current transaction
        // is aborted") y la venta se caía con un 500 sin causa visible. Los
        // readers de campos demoted que hacen SQL crudo hay que migrarlos a
        // leer del JSONB — el `_flattenJsonb` de los `SELECT *` no los cubre.
        //
        // El CASE no es decorativo: `itemWaste` está guardado como número en
        // 150 ítems pero como booleano en al menos uno, y un `::numeric` suelto
        // revienta con ese dato. Filtrar por tipo ANTES de castear es la única
        // forma determinística — Postgres puede reordenar los AND de un WHERE,
        // así que un guard de tipo al lado del cast no alcanza.
        $params = [COMPANY_ID];
        $andId  = '';
        $limit  = ' LIMIT 500';

        if ($id) {
            // Parametrizado, NO interpolado: además de la inyección, un UUID
            // sin comillas es un error de sintaxis en Postgres.
            $andId  = ' AND itemId = ?';
            $limit  = ' LIMIT 1';
            $params[] = $id;
        }

        $sql = 'SELECT itemId, waste
                  FROM (
                        SELECT itemId,
                               CASE WHEN jsonb_typeof(data->\'itemWaste\') = \'number\'
                                    THEN (data->>\'itemWaste\')::numeric
                                    ELSE 0
                               END AS waste
                          FROM item
                         WHERE companyId = ?' . $andId . '
                       ) t
                 WHERE waste > 0' . $limit;

        $result = ncmExecute($sql, $params, $cache, true);
        $out    = [];

        if ($result) {
            while (!$result->EOF) {
                $fields                 = $result->fields;
                $out[$fields['itemId']] = $fields['waste'];
                $result->MoveNext();
            }
            $result->Close();
        }

        return $out;
    }

    /**
     * Cantidad de insumo CRUDO a consumir para obtener `$need` de producto
     * ÚTIL, dado un porcentaje de merma de RENDIMIENTO (`wasteP`).
     * Equivalente legacy: `getNeedWithWaste($need, $wasteP)`.
     *
     * Semántica: `wasteP` es la fracción del insumo que se PIERDE al
     * procesarlo (no un recargo aditivo sobre `$need`). Ej.: carne con 30%
     * de merma → 1kg crudo rinde 700g útiles. Para obtener 700g útiles hace
     * falta consumir `700 / (1 - 0.30) = 1000g` de insumo crudo. Fórmula:
     * `need / (1 - wasteP/100)`.
     *
     * Guards:
     * - `wasteP <= 0`: sin merma, devuelve `$need` sin cambios.
     * - `wasteP >= 100`: rendimiento 0 → consumo infinito, físicamente
     *   imposible. Se clampea a 99 (el máximo consumo finito representable)
     *   y se loguea la advertencia — evita división por cero sin ocultar el
     *   dato inválido en el ledger de `item.itemWaste`.
     */
    public static function getNeedWithWaste(mixed $need, mixed $wasteP): int|float
    {
        $need   = (float) $need;
        $wasteP = (float) $wasteP;

        if ($wasteP <= 0) {
            return $need;
        }

        if ($wasteP >= 100) {
            error_log("Inventory::getNeedWithWaste: wasteP={$wasteP} >= 100 (rendimiento 0, consumo infinito) — clampeado a 99");
            $wasteP = 99;
        }

        return $need / (1 - $wasteP / 100);
    }
}
