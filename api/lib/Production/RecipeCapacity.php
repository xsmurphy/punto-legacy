<?php
declare(strict_types=1);

namespace Punto\Api\Production;

use Punto\App\Domain\Inventory;

/**
 * RecipeCapacity — cuántas unidades de un ítem con receta salen HOY con los
 * insumos que hay, y cuál es el insumo que corta.
 *
 * Pedido del owner (2026-09-07): "si entro al Café Latte —que se produce sobre
 * pedido y a ojo no podés saber cuántas unidades te quedan— el sistema te dice
 * que hay 25 disponibles, calculado con su receta sobre los insumos
 * disponibles. Si uno de esos insumos está en 0, marca 0."
 *
 * ── Por qué esta clase existe y no es un método más de ProductionService ─────
 *
 * Antes había DOS definiciones de "cuántas salen":
 *
 *   1. `ProductionService::capacity()` — `getCompoundsArray()` directo, UN solo
 *      nivel de receta. Una sub-preparación sin stock propio (producción
 *      directa anidada) caía en la rama `!tracked` y NO limitaba, así que la
 *      capacidad salía INFLADA: el plato decía "se pueden hacer 40" con la
 *      harina de su masa en cero. Es exactamente el caso que el owner nombró.
 *   2. `ProductionBatchService::estimate()` — `explodeRecipeDetailed()`, la
 *      explosión canónica, la misma que mueve el stock.
 *
 * La (1) divergía de la (2) y de lo que la operación realmente descuenta. Esta
 * clase deja UNA sola: la canónica. `capacity()` y `producible()` son ahora dos
 * lecturas de este motor, no dos aritméticas.
 *
 * ── Reglas (el server las hace valer; el front solo pinta) ───────────────────
 *
 *   - `N = floor(min sobre insumos que LIMITAN de (onHand / neededPerUnit))`.
 *   - `neededPerUnit` ya viene con la merma planificada (`item.itemWaste`)
 *     aplicada NIVEL POR NIVEL por `explodeRecipeDetailed()` — no es
 *     `qty × (1 + merma)`: la fórmula de rendimiento es `need / (1 - w/100)`
 *     (`Inventory::getNeedWithWaste`), y en una receta de tres niveles se
 *     compone tres veces. Por eso el detalle NO expone un `wastePercent`
 *     escalar: para una hoja alcanzada por dos ramas distintas ese número no
 *     existe.
 *   - Un insumo LIMITA si `stockLeaf === true` **y** `itemTrackInventory >= 1`.
 *     Las dos condiciones, no una: `stockLeaf` dice que la explosión corta ahí
 *     (no se re-explota un semielaborado con stock propio), y
 *     `itemTrackInventory` dice que ese ítem realmente se descuenta —
 *     `Inventory::manageStock()` es no-op por debajo de 1. Un ítem marcado
 *     `itemProduction` con el trackeo apagado nunca mueve una fila de ledger;
 *     tratarlo como limitante lo haría cortar en 0 para siempre.
 *   - Insumo sin control de inventario (agua, sal) NO limita. Misma honestidad
 *     que la D1 de `context/70`: sin ledger no hay saldo cero, hay saldo
 *     DESCONOCIDO, y un 0 inventado apagaría el número de toda la receta.
 *   - Si NINGÚN insumo limita, `capacity` es `null` — "no hay número", nunca
 *     un infinito ni un 0. Contrato preexistente de `capacity()` y de
 *     `batchCapacity`: `null` NO es 0.
 *
 * Lectura pura, sin caché persistida: el número vale para el instante en que se
 * mira. NO se expone en el listado del catálogo (una columna por receta sobre
 * 500 ítems es un N+1 de explosiones; si algún día se quiere, es con rollup) ni
 * en el POS (el snapshot offline no baja stock — F2 de `context/63`; mezclar un
 * número online en una caja offline-first es otra discusión).
 */
final class RecipeCapacity
{
    /**
     * Tolerancia al comparar el cociente contra su piso. Los saldos son
     * `NUMERIC(15,3)` que llegan a PHP como float: 25 unidades justas pueden
     * dar 24.999999999 y `floor()` devolvería 24 — un "te queda uno menos" que
     * el dueño no puede explicar mirando su depósito.
     */
    private const EPS = 1e-9;

    /**
     * La parte de la receta que NO depende de la sucursal: qué se consume por
     * unidad y con qué metadatos. Se resuelve UNA vez y se evalúa contra N
     * sucursales, en vez de explotar la misma receta N veces.
     *
     * @return array{
     *   hasRecipe: bool,
     *   leaves: list<array{itemId:string,itemName:string,neededPerUnit:float,limits:bool}>,
     *   direct: list<array{itemId:string,itemName:string,neededPerUnit:float,tracked:bool}>
     * }
     * @throws \InvalidArgumentException si el ítem no es de este tenant
     */
    public static function recipeFor(string $companyId, string $itemId): array
    {
        // Aislamiento multi-tenant en el motor y no en cada caller: los dos
        // endpoints que lo usan reciben el itemId del query string.
        $item = ncmExecute(
            'SELECT itemid FROM item WHERE itemid = ? AND companyid = ? AND itemstatus = 1 LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$item) {
            throw new \InvalidArgumentException('itemId inválido para este tenant');
        }

        $compounds = Inventory::getCompoundsArray($itemId);
        if (!is_array($compounds) || $compounds === []) {
            return ['hasRecipe' => false, 'leaves' => [], 'direct' => []];
        }

        // La MISMA explosión que descuenta el stock, por UNA unidad del padre.
        // Es lineal en las unidades (docblock de `explodeRecipeDetailed`), así
        // que el consumo de N unidades es N × esto.
        $rawLeaves = Inventory::explodeRecipeDetailed($itemId, $companyId, 1.0);

        // Una hoja puede aparecer por más de una rama (el azúcar del almíbar y
        // el de la crema son el mismo saldo). Sumar, no pisar: dos ramas que se
        // pisan devuelven un número creíble y equivocado.
        $byLeaf = [];
        foreach ($rawLeaves as $leaf) {
            $id = (string) $leaf['itemId'];
            if (!isset($byLeaf[$id])) {
                $byLeaf[$id] = ['qty' => 0.0, 'stockLeaf' => (bool) $leaf['stockLeaf']];
            }
            $byLeaf[$id]['qty'] += (float) $leaf['qty'];
            // Si alguna rama la alcanzó como hoja de stock, es hoja de stock:
            // ese saldo se descuenta igual aunque otra rama la haya emitido
            // como hoja de costeo.
            $byLeaf[$id]['stockLeaf'] = $byLeaf[$id]['stockLeaf'] || (bool) $leaf['stockLeaf'];
        }

        $directWaste = Inventory::getAllWasteValue();
        $meta        = self::itemMeta(
            $companyId,
            array_merge(array_keys($byLeaf), array_column($compounds, 'compoundId'))
        );

        $leaves = [];
        foreach ($byLeaf as $id => $row) {
            $tracked  = (bool) ($meta[$id]['tracked'] ?? false);
            $leaves[] = [
                'itemId'        => $id,
                'itemName'      => (string) ($meta[$id]['name'] ?? ''),
                'neededPerUnit' => $row['qty'],
                'limits'        => $row['stockLeaf'] && $tracked,
            ];
        }

        // Nivel 1 de la receta. NO alimenta el cálculo — existe porque
        // `ProductionService::complete()` indexa `ingredientAdjustments` por
        // insumo DIRECTO (itera `getCompoundsArray($itemId)`), así que el
        // diálogo de completar una orden tiene que ofrecer esos ids y no los de
        // las hojas: un ajuste tecleado contra una hoja de tercer nivel sería
        // un no-op silencioso.
        $direct = [];
        foreach ($compounds as $comp) {
            $childId = (string) ($comp['compoundId'] ?? '');
            if ($childId === '') {
                continue;
            }
            $direct[] = [
                'itemId'   => $childId,
                'itemName' => (string) ($meta[$childId]['name'] ?? ''),
                // Misma fórmula de rendimiento que usa `complete()` para
                // calcular el consumo teórico. El front la calculaba como
                // `qty × (1 + w/100)`, que no es la del server: con 20% de
                // merma el placeholder decía 12 donde se consumen 12,5.
                'neededPerUnit' => (float) Inventory::getNeedWithWaste(
                    (float) ($comp['toCompoundQty'] ?? 0),
                    $directWaste[$childId] ?? 0
                ),
                'tracked' => (bool) ($meta[$childId]['tracked'] ?? false),
            ];
        }

        return ['hasRecipe' => true, 'leaves' => $leaves, 'direct' => $direct];
    }

    /**
     * La aritmética, contra el saldo de UNA sucursal.
     *
     * @param array{hasRecipe:bool,leaves:list<array<string,mixed>>,direct:list<array<string,mixed>>} $recipe
     * @param array<string,float> $onHand itemId => saldo (`Inventory::onHandFor`)
     * @return array{capacity:int|null,limiting:array<string,mixed>|null,ingredients:list<array<string,mixed>>}
     */
    public static function evaluate(array $recipe, array $onHand): array
    {
        $ingredients = [];
        $capacity    = null;
        $limitingIdx = null;

        foreach ($recipe['leaves'] as $leaf) {
            $needed = (float) $leaf['neededPerUnit'];
            $limits = (bool) $leaf['limits'];

            if (!$limits || $needed <= 0) {
                // Sin ledger no hay saldo: `null` es "desconocido", distinto de
                // 0. El front lo pinta como "no limita", nunca como faltante.
                $ingredients[] = [
                    'itemId'         => $leaf['itemId'],
                    'itemName'       => $leaf['itemName'],
                    'onHand'         => null,
                    'neededPerUnit'  => $needed,
                    'unitsSupported' => null,
                    'limiting'       => false,
                    'tracked'        => false,
                ];
                continue;
            }

            // Ausente del mapa = ese insumo nunca tuvo movimiento en la
            // sucursal: saldo 0 REAL (lleva ledger), no "desconocido".
            $have = (float) ($onHand[$leaf['itemId']] ?? 0.0);

            // Saldo en 0 o negativo ⇒ 0 unidades. Es el caso que el owner
            // nombró explícito, y sale solo de la fórmula: no hay rama especial.
            $supported = $have <= 0 ? 0 : (int) floor(($have / $needed) + self::EPS);

            $ingredients[] = [
                'itemId'         => $leaf['itemId'],
                'itemName'       => $leaf['itemName'],
                'onHand'         => $have,
                'neededPerUnit'  => $needed,
                'unitsSupported' => $supported,
                'limiting'       => false,
                'tracked'        => true,
            ];

            // Estrictamente menor: ante un empate gana el primero, que es el
            // orden en que la explosión recorrió la receta.
            if ($capacity === null || $supported < $capacity) {
                $capacity    = $supported;
                $limitingIdx = count($ingredients) - 1;
            }
        }

        if ($limitingIdx !== null) {
            $ingredients[$limitingIdx]['limiting'] = true;
        }

        return [
            // null = ningún insumo con control de inventario limita. NO es 0:
            // 0 significa "no se puede producir ni una".
            'capacity'    => $capacity,
            'limiting'    => $limitingIdx === null ? null : $ingredients[$limitingIdx],
            'ingredients' => $ingredients,
        ];
    }

    /**
     * Nombre + flag de control de stock de un conjunto de ítems, en UNA query.
     *
     * Vive acá y no en cada servicio de producción porque tanto la capacidad de
     * un plato como la estimación de un lote necesitan exactamente esto sobre
     * las hojas de la misma explosión.
     *
     * @param  list<string> $itemIds
     * @return array<string,array{name:string,tracked:bool}>
     */
    public static function itemMeta(string $companyId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v): string => (string) $v, $itemIds),
            static fn (string $v): bool => $v !== '',
        )));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params       = $ids;
        $params[]     = $companyId;

        // forceObj=true → recordset, NO array: hay que iterar con
        // `while (!$rs->EOF)`. Tratarlo como array devuelve [] siempre.
        $rs = ncmExecute(
            "SELECT itemid, itemname, itemtrackinventory
               FROM item
              WHERE itemid IN ($placeholders) AND companyid = ?
              LIMIT 1000",
            $params,
            false,
            true
        );

        $out = [];
        if ($rs !== false && is_object($rs)) {
            while (!$rs->EOF) {
                $out[(string) ($rs->fields['itemid'] ?? '')] = [
                    'name'    => (string) ($rs->fields['itemname'] ?? ''),
                    'tracked' => !empty($rs->fields['itemtrackinventory']),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $out;
    }
}
