<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * ItemCompoundService — gestión de recetas (ingredientes de un item de producción).
 *
 * Reemplaza el approach legacy donde los ingredientes eran items hijo con
 * data.parent_id = parentId. Acá usamos una join table dedicada (migration 19).
 *
 * El servicio devuelve los ingredientes "presentados" — con el nombre, SKU,
 * costo y UOM del item child resueltos via JOIN, listos para mostrar sin
 * lookup adicional del frontend.
 *
 * Validaciones:
 *   - parentItemId y childItemId deben existir y ser del companyId
 *   - parentItemId != childItemId (no recursión simple)
 *   - quantity > 0
 */
final class ItemCompoundService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /** Lista de ingredientes de un item, con datos del child resueltos. */
    public function listForParent(string $parentItemId, string $companyId, ?string $outletId = null): array
    {
        return $this->recipe($parentItemId, $companyId, $outletId)['compounds'];
    }

    /**
     * La receta completa: ingredientes + los DOS totales de costo.
     *
     * Por qué dos y no uno (reporte del tester "Actualización 21" #1): la
     * ficha mostraba `Σ quantity × item.itemCost` — costo de CATÁLOGO, sin
     * merma y de un solo nivel — mientras la venta del mismo ítem registraba
     * el costo REAL (promedio ponderado del ledger, con merma, recursivo). Dos
     * números para "lo que cuesta esta hamburguesa", y el dueño no sabía cuál
     * creer. Ahora se muestran los dos, con su nombre:
     *
     *   - `catalogCost` / `catalogTotal` — lo que dice el catálogo. Es el
     *     número que el dueño CARGÓ, y sigue siendo útil como referencia y
     *     para detectar costos de catálogo desactualizados.
     *   - `currentCost` / `currentTotal` — lo que cuesta HOY producir una
     *     unidad, calculado por `RecipeCosting` (la única fórmula del
     *     sistema): explosión recursiva, merma planificada por nivel,
     *     promedio ponderado por sucursal con fallback al catálogo. Es
     *     EXACTAMENTE el número que la venta va a guardar en
     *     `itemSold.itemSoldCOGS`.
     *
     * Ambos son por unidad del padre; `line*` es el total de esa fila. El
     * front NO recalcula ninguno de los dos — recalcular en el cliente fue
     * cómo la ficha terminó con su propia fórmula.
     *
     * `$outletId` es la sucursal contra la que se valúa el costo real. Sin
     * ella cae en la de la sesión (`OUTLET_ID`), que es lo correcto para la
     * ficha del panel: el dueño está mirando SU sucursal activa.
     *
     * @return array{compounds: list<array<string,mixed>>, totals: array{catalogTotal: float, currentTotal: float|null}}
     */
    public function recipe(string $parentItemId, string $companyId, ?string $outletId = null): array
    {
        $empty = ['compounds' => [], 'totals' => ['catalogTotal' => 0.0, 'currentTotal' => 0.0]];

        // itemUOM vive en el JSONB `data` desde la migración 07 (demoted del
        // schema físico). Hay que leerlo via `data->>'itemUOM'` — buscarlo
        // como columna física falla con "column i.itemuom does not exist".
        $sql = "SELECT ic.compoundId, ic.parentItemId, ic.childItemId, ic.quantity, ic.sort,
                       i.itemName, i.itemSKU, i.itemCost, i.itemPrice, i.itemKind,
                       i.data->>'itemUOM' AS itemUOM
                  FROM item_compound ic
             LEFT JOIN item i ON i.itemId = ic.childItemId
                 WHERE ic.parentItemId = ? AND ic.companyId = ?
                 ORDER BY ic.sort ASC, ic.created_at ASC";
        $rs = $this->db->Execute($sql, [$parentItemId, $companyId]);
        if ($rs === false) return $empty;

        $rows = $rs->GetRows();
        if ($rows === []) return $empty;

        // UNA sola explosión para toda la receta: `byChild` ya trae el costo
        // real agrupado por ingrediente directo, incluidas las sub-recetas que
        // cuelgan de él. Costear fila por fila serían N explosiones del mismo
        // árbol.
        //
        // Sin sucursal resoluble no hay costo real posible: los campos
        // `current*` viajan NULL y la ficha muestra "—". Es una pantalla de
        // lectura — mejor un guion honesto que un 0 que parece un costo.
        $costing = null;
        try {
            $costing = \Punto\App\Domain\RecipeCosting::cost(
                $parentItemId,
                $companyId,
                $outletId ?: (defined('OUTLET_ID') ? OUTLET_ID : '')
            );
        } catch (\InvalidArgumentException $e) {
            error_log('ItemCompoundService::recipe: sin outlet para costear la receta — ' . $e->getMessage());
        }
        $byChild = $costing['byChild'] ?? [];

        $out          = [];
        $catalogTotal = 0.0;
        foreach ($rows as $r) {
            $childId = (string) ($r['childitemid'] ?? $r['childItemId'] ?? '');
            $qty     = (float) ($r['quantity'] ?? 0);
            $cost    = (float) ($r['itemcost'] ?? $r['itemCost'] ?? 0);
            // itemPrice del hijo: es lo que costaría comprar ese componente por
            // separado. Lo consume comboPricing() para el descuento implícito
            // del combo fijo (F5, context/41) — el costo NO sirve para eso.
            $price = (float) ($r['itemprice'] ?? $r['itemPrice'] ?? 0);

            $lineCatalogCost = round($qty * $cost, 4);
            // Un ingrediente sin entrada en `byChild` es uno que la explosión
            // no alcanzó (cantidad 0). Su costo real es 0, no el de catálogo —
            // rellenar con el catálogo escondería justamente la diferencia que
            // esta pantalla existe para mostrar.
            $lineCurrentCost = $costing === null
                ? null
                : round((float) ($byChild[$childId] ?? 0.0), 4);

            $catalogTotal += $lineCatalogCost;

            $out[] = [
                'compoundId'      => $r['compoundid']   ?? $r['compoundId']   ?? null,
                'parentItemId'    => $r['parentitemid'] ?? $r['parentItemId'] ?? null,
                'childItemId'     => $childId !== '' ? $childId : null,
                'quantity'        => $qty,
                'sort'            => (int) ($r['sort'] ?? 0),
                'childName'       => $r['itemname']     ?? $r['itemName']     ?? null,
                'childSKU'        => $r['itemsku']      ?? $r['itemSKU']      ?? null,
                'childUOM'        => $r['itemuom']      ?? $r['itemUOM']      ?? null,
                'childCost'       => $cost,
                'childPrice'      => $price,
                'childKind'       => $r['itemkind']     ?? $r['itemKind']     ?? null,
                'catalogCost'     => $cost,
                'currentCost'     => ($lineCurrentCost !== null && $qty > 0)
                    ? round($lineCurrentCost / $qty, 4)
                    : $lineCurrentCost,
                'lineCatalogCost' => $lineCatalogCost,
                'lineCurrentCost' => $lineCurrentCost,
                // @deprecated alias de `lineCatalogCost` — sigue existiendo
                // porque su nombre no decía CUÁL de los dos costos era, que es
                // exactamente cómo esta pantalla terminó mostrando uno y la
                // venta registrando otro.
                'lineCost'        => $lineCatalogCost,
                'linePrice'       => round($qty * $price, 4),
            ];
        }

        return [
            'compounds' => $out,
            'totals'    => [
                'catalogTotal' => round($catalogTotal, 4),
                'currentTotal' => $costing === null ? null : round((float) $costing['total'], 4),
            ],
        ];
    }

    /**
     * Descuento implícito de un combo fijo (F5, context/41).
     *
     * El combo fijo tiene precio propio (`item.itemPrice`) y su receta vive en
     * `item_compound`. La diferencia entre "comprar los componentes sueltos" y
     * "comprar el combo" es el descuento que el cliente recibe sin que exista
     * ninguna fila de descuento: está implícito en el precio. El dueño no lo ve
     * en ningún lado hoy, y es EL número que decide si el combo tiene sentido.
     *
     * Solo lectura y derivado — no hay columna ni migración detrás. Esta es la
     * ÚNICA implementación de la fórmula: cualquier consumidor (ficha, ticket,
     * reportes) la lee de acá en vez de recalcularla.
     *
     * `discount` puede ser NEGATIVO: un combo más caro que la suma de sus
     * partes es un dato legítimo (y probablemente un error de carga que el
     * dueño quiere ver), no un caso a esconder con un max(0).
     *
     * @return array{componentsSum:float,comboPrice:float,discount:float,discountPct:float}|null
     *   null cuando el combo no tiene componentes cargados todavía — no hay
     *   nada con qué comparar, y un 0% ahí mentiría.
     */
    public function comboPricing(string $parentItemId, string $companyId, float $comboPrice): ?array
    {
        $components = $this->listForParent($parentItemId, $companyId);
        if ($components === []) {
            return null;
        }

        $sum = 0.0;
        foreach ($components as $c) {
            $sum += (float) $c['linePrice'];
        }

        $discount = $sum - $comboPrice;

        return [
            'componentsSum' => round($sum, 4),
            'comboPrice'    => round($comboPrice, 4),
            'discount'      => round($discount, 4),
            // Porcentaje sobre la suma de componentes (la base contra la que el
            // cliente compara). Sin componentes con precio la base es 0 y el
            // porcentaje no existe: 0.0 en vez de una división por cero.
            'discountPct'   => $sum > 0 ? round(($discount / $sum) * 100, 2) : 0.0,
        ];
    }

    /** Crea un compound. Si ya existe (UNIQUE parent+child), suma la cantidad. */
    public function add(string $parentItemId, string $companyId, string $childItemId, float $quantity): string
    {
        if ($parentItemId === $childItemId) {
            throw new \RuntimeException('Un item no puede ser ingrediente de sí mismo');
        }
        if ($quantity <= 0) {
            throw new \RuntimeException('Cantidad debe ser mayor a 0');
        }
        $this->assertItemOwnedByCompany($childItemId, $companyId, 'child');
        $this->assertItemOwnedByCompany($parentItemId, $companyId, 'parent');
        $this->assertNoCycle($parentItemId, $childItemId, $companyId);

        // Si ya existe, sumamos cantidad en vez de fallar.
        $existing = $this->db->Execute(
            'SELECT compoundId, quantity FROM item_compound
              WHERE parentItemId = ? AND childItemId = ? AND companyId = ? LIMIT 1',
            [$parentItemId, $childItemId, $companyId]
        );
        if ($existing !== false && !$existing->EOF) {
            $compoundId = (string) ($existing->fields['compoundid'] ?? $existing->fields['compoundId']);
            $newQty     = (float) ($existing->fields['quantity']) + $quantity;
            $this->db->Execute(
                'UPDATE item_compound SET quantity = ? WHERE compoundId = ?',
                [$newQty, $compoundId]
            );
            return $compoundId;
        }

        $compoundId = $this->generateUuid();
        $sort       = $this->nextSort($parentItemId, $companyId);
        $ok = $this->db->Execute(
            'INSERT INTO item_compound (compoundId, parentItemId, childItemId, quantity, sort, companyId)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$compoundId, $parentItemId, $childItemId, $quantity, $sort, $companyId]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo guardar el ingrediente');
        return $compoundId;
    }

    public function updateQuantity(string $parentItemId, string $companyId, string $compoundId, float $quantity): void
    {
        if ($quantity <= 0) throw new \RuntimeException('Cantidad debe ser mayor a 0');
        $ok = $this->db->Execute(
            'UPDATE item_compound SET quantity = ?
              WHERE compoundId = ? AND parentItemId = ? AND companyId = ?',
            [$quantity, $compoundId, $parentItemId, $companyId]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo actualizar el ingrediente');
    }

    public function delete(string $parentItemId, string $companyId, string $compoundId): void
    {
        $ok = $this->db->Execute(
            'DELETE FROM item_compound
              WHERE compoundId = ? AND parentItemId = ? AND companyId = ?',
            [$compoundId, $parentItemId, $companyId]
        );
        if ($ok === false) throw new \RuntimeException('No se pudo eliminar el ingrediente');
    }

    /** Reordena. $compoundIds[] define el orden. Filas no listadas mantienen su sort. */
    public function reorder(string $parentItemId, string $companyId, array $compoundIds): void
    {
        foreach ($compoundIds as $i => $cid) {
            $this->db->Execute(
                'UPDATE item_compound SET sort = ?
                  WHERE compoundId = ? AND parentItemId = ? AND companyId = ?',
                [$i, $cid, $parentItemId, $companyId]
            );
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    /**
     * Rechaza si agregar $childItemId como ingrediente de $parentItemId
     * crearía un ciclo (ej. A usa B, B usa A; o A usa B, B usa C, C usa A).
     * Recorre children del candidato childItemId recursivamente: si en algún
     * punto llegamos a $parentItemId, agregarlo lo convertiría en su propio
     * ancestro. Scoped a companyId, con límite de profundidad como cinturón
     * de seguridad ante datos corruptos preexistentes.
     */
    private function assertNoCycle(string $parentItemId, string $childItemId, string $companyId, int $maxDepth = 50): void
    {
        if ($childItemId === $parentItemId) {
            // Ya cubierto por el chequeo de arriba en add(), pero defensivo acá también.
            throw new \RuntimeException('Un item no puede ser ingrediente de sí mismo');
        }

        $visited = [];
        $queue   = [$childItemId];
        $depth   = 0;

        while ($queue !== [] && $depth < $maxDepth) {
            $next = [];
            foreach ($queue as $current) {
                if (isset($visited[$current])) {
                    continue;
                }
                $visited[$current] = true;

                if ($current === $parentItemId) {
                    throw new \RuntimeException('Esta receta crearía un ciclo: el ingrediente ya depende de este item');
                }

                $rs = $this->db->Execute(
                    'SELECT childItemId FROM item_compound WHERE parentItemId = ? AND companyId = ?',
                    [$current, $companyId]
                );
                if ($rs !== false) {
                    foreach ($rs->GetRows() as $r) {
                        $grandchild = $r['childitemid'] ?? $r['childItemId'] ?? null;
                        if ($grandchild !== null && !isset($visited[$grandchild])) {
                            $next[] = $grandchild;
                        }
                    }
                }
            }
            $queue = $next;
            $depth++;
        }
    }

    private function assertItemOwnedByCompany(string $itemId, string $companyId, string $role): void
    {
        $rs = $this->db->Execute(
            'SELECT 1 FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException("Item $role no existe o no pertenece a tu empresa");
        }
    }

    private function nextSort(string $parentItemId, string $companyId): int
    {
        $rs = $this->db->Execute(
            'SELECT COALESCE(MAX(sort), -1) + 1 AS next_sort FROM item_compound
              WHERE parentItemId = ? AND companyId = ?',
            [$parentItemId, $companyId]
        );
        return ($rs !== false && !$rs->EOF) ? (int) ($rs->fields['next_sort'] ?? 0) : 0;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
