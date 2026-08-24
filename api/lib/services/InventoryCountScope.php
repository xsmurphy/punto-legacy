<?php
declare(strict_types=1);
namespace Punto\Api\Services;

use Punto\App\Domain\Inventory;

/**
 * InventoryCountScope — alcance de una toma física (mig 158).
 *
 * ÚNICO lugar del codebase que decide QUÉ ítems entran en un conteo. Tanto
 * `InventoryCountService::create()` (que los snapshotea) como el
 * `action=preview` del endpoint (que solo los cuenta) arman su SQL desde
 * acá — así el "vas a contar N artículos" que ve el operador y lo que
 * termina en `inventory_count_item` NO pueden divergir.
 *
 * Tres filtros, todos sobre `item`:
 *
 *  1. Trackeable y activo del tenant — `companyid`, `itemstatus = 1`,
 *     `itemtrackinventory = true`. Igual que antes.
 *
 *  2. PRESENCIA en la sucursal / depósito (el bug que esta clase repara).
 *     El catálogo de Punto es tenant-scoped (context/25): ningún ítem tiene
 *     columna que lo ate a una sucursal. La pertenencia se INFIERE de que
 *     exista movimiento del ítem en `stock` para ese `outletid` — o fila en
 *     el ledger filtrado por ese `locationid` cuando el conteo es de un depósito
 *     puntual. Es EXISTS, no JOIN: `stock` es un ledger (N filas por ítem) y
 *     un JOIN duplicaría líneas del conteo.
 *     `includeZeroStock = true` salta este filtro: sirve para el primer
 *     conteo de una sucursal recién abierta, donde todavía no hay ni un
 *     movimiento y sin embargo hay mercadería física en la góndola.
 *
 *  3. CATEGORÍA, por los DOS caminos que conviven en el schema: la FK legacy
 *     `item.categoryid` (1:1) y el m2m `item_category` (mig 16). Mirar solo
 *     uno deja ítems afuera — un ítem viejo puede tener la legacy y ninguna
 *     fila en el m2m, y uno nuevo al revés. Mismo criterio que la mig 136
 *     (`136_combo_group_to_addon.sql:186-196`).
 *
 * Los `categoryIds` se validan contra el tenant ANTES de entrar al SQL
 * (`assertCategoriesOwned`): un UUID de otra company filtraría un conteo
 * ajeno a cero líneas en el mejor caso, y es un probe de existencia
 * cross-tenant en el peor.
 */
final class InventoryCountScope
{
    /** @param string[] $categoryIds */
    private function __construct(
        private readonly string $companyId,
        private readonly string $outletId,
        private readonly ?string $locationId,
        private readonly array $categoryIds,
        private readonly bool $includeZeroStock,
    ) {
    }

    /**
     * Normaliza y valida el alcance pedido. Lanza InvalidArgumentException si
     * la sucursal, el depósito o alguna categoría no son del tenant.
     *
     * @param string[] $categoryIds
     */
    public static function forRequest(
        string $companyId,
        string $outletId,
        ?string $locationId,
        array $categoryIds,
        bool $includeZeroStock,
    ): self {
        $outlet = ncmExecute(
            'SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1',
            [$outletId, $companyId]
        );
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        $locationId = $locationId ?: null;
        if ($locationId !== null) {
            // El depósito tiene que colgar de la MISMA sucursal elegida: sin
            // este chequeo se podía abrir un conteo de la sucursal A contra
            // el depósito de B y el ajuste final caía en el outlet equivocado.
            $loc = ncmExecute(
                "SELECT taxonomyid FROM taxonomy
                  WHERE taxonomyid = ? AND companyid = ? AND taxonomytype = 'location'
                    AND outletid = ? LIMIT 1",
                [$locationId, $companyId, $outletId]
            );
            if (!$loc) {
                throw new \InvalidArgumentException('locationId inválido para esta sucursal');
            }
        }

        $categoryIds = self::assertCategoriesOwned($companyId, $categoryIds);

        return new self($companyId, $outletId, $locationId, $categoryIds, $includeZeroStock);
    }

    /**
     * Rehidrata el alcance persistido en `inventory_count.scope` (mig 158).
     * Las filas anteriores a la migración traen `{}` — alcance desconocido,
     * se devuelve tal cual para que el lector pueda distinguirlo.
     *
     * @return array{categoryIds: string[], includeZeroStock: bool}|array{}
     */
    public static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) ($raw ?? ''), true);
        }
        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        return [
            'categoryIds'      => array_values(array_filter(
                (array) ($decoded['categoryIds'] ?? []),
                'is_string'
            )),
            'includeZeroStock' => (bool) ($decoded['includeZeroStock'] ?? false),
        ];
    }

    /** Payload jsonb a persistir en `inventory_count.scope`. */
    public function toJson(): string
    {
        return (string) json_encode([
            'categoryIds'      => $this->categoryIds,
            'includeZeroStock' => $this->includeZeroStock,
        ]);
    }

    public function outletId(): string
    {
        return $this->outletId;
    }

    public function locationId(): ?string
    {
        return $this->locationId;
    }

    /**
     * Ítems del conteo CON su cantidad esperada y costo unitario resueltos en
     * una sola pasada.
     *
     * MONEY PATH — `expectedqty` congela la BASE del ajuste que el conteo
     * genera: si el esperado está mal, el ajuste está mal, y el ajuste escribe
     * el ledger.
     *
     * F1 de context/52: el esperado es `SUM(stockcount)`, la definición del
     * saldo (D1). Antes salía del SNAPSHOT `stockonhand` de la fila vigente —
     * un acumulado cacheado al INSERT que un movimiento con fecha retroactiva
     * deja desactualizado, mientras el tab Stock que el operador mira al
     * contar ya mostraba el SUM. Contar contra un esperado distinto del que
     * muestra la pantalla produce una diferencia fantasma y un ajuste que la
     * "corrige" moviendo stock real.
     *
     * El conteo POR DEPÓSITO tampoco lee ya `tolocation` (tabla espejo que no
     * se escribe más, context/52 D4): el ledger guarda `locationid` por fila,
     * así que el esperado del depósito es el mismo SUM filtrado — pero filtrado
     * por el depósito EFECTIVO (`Inventory::ledgerLocationId()`), no por la
     * columna cruda. Desde la mig 165 toda sucursal tiene depósito por defecto
     * y las filas históricas quedaron con `locationid IS NULL`: el tab Stock
     * las consolida en ese depósito y el conteo, filtrando por la columna, las
     * dejaba afuera. El esperado arrancaba en 0 contra mercadería que existe y
     * el ajuste del conteo la "corregía" moviendo stock real — exactamente la
     * diferencia fantasma que este docblock advierte arriba. La consolidación
     * es la MISMA definición que usan `StockMovementsService::breakdown()` y
     * `Reports\StockService`: vive una sola vez, en el lector del ledger.
     * Ahí sí hay
     * costo unitario disponible (el promedio de la fila vigente), pero se
     * mantiene en 0 a propósito para no cambiar la valorización del conteo por
     * depósito en esta pasada.
     *
     * El costo unitario sigue saliendo de la fila VIGENTE del ledger
     * (`ORDER BY stockdate DESC, stockid DESC LIMIT 1`): `stockonhandcogs` es
     * un promedio ponderado móvil, no un acumulado sumable.
     *
     * @return array{0: string, 1: array<int, mixed>} [sql, params]
     */
    public function itemsQuery(): array
    {
        [$where, $params] = $this->filter();

        if ($this->locationId !== null) {
            // Depósito EFECTIVO, no `s2.locationid` crudo — ver el docblock de
            // `filter()`. Filtrar por la columna dejaría afuera el histórico en
            // NULL que el tab Stock ya muestra dentro del depósito por defecto.
            $effLoc = Inventory::ledgerLocationId('s2', 'dl2');
            $sql = "SELECT i.itemid,
                           COALESCE(saldo.qty, 0) AS expectedqty,
                           0::numeric             AS unitcost
                      FROM item i
                      LEFT JOIN LATERAL (
                           SELECT COALESCE(SUM(s2.stockcount), 0) AS qty
                             FROM stock s2"
                           . Inventory::ledgerLocationJoin('s2', 'dl2') .
                           "WHERE s2.itemid = i.itemid
                              AND s2.outletid = ?
                              AND {$effLoc} = ?
                      ) saldo ON true
                     WHERE {$where}
                     ORDER BY i.itemname ASC";
            $params = array_merge([$this->outletId, $this->locationId], $params);
        } else {
            $sql = "SELECT i.itemid,
                           COALESCE(saldo.qty, 0)         AS expectedqty,
                           COALESCE(s.stockonhandcogs, 0) AS unitcost
                      FROM item i
                      LEFT JOIN LATERAL (
                           SELECT COALESCE(SUM(s2.stockcount), 0) AS qty
                             FROM stock s2
                            WHERE s2.itemid = i.itemid AND s2.outletid = ?
                      ) saldo ON true
                      LEFT JOIN LATERAL (
                           SELECT s3.stockonhandcogs
                             FROM stock s3
                            WHERE s3.itemid = i.itemid AND s3.outletid = ?
                            ORDER BY s3.stockdate DESC, s3.stockid DESC
                            LIMIT 1
                      ) s ON true
                     WHERE {$where}
                     ORDER BY i.itemname ASC";
            $params = array_merge([$this->outletId, $this->outletId], $params);
        }

        return [$sql, $params];
    }

    /**
     * Cuántos ítems entrarían con este alcance, sin crear nada
     * (`action=preview`). Comparte el WHERE con itemsQuery() — es el mismo
     * conjunto por construcción, no por coincidencia.
     *
     * @return array{0: string, 1: array<int, mixed>} [sql, params]
     */
    public function countQuery(): array
    {
        [$where, $params] = $this->filter();

        return ["SELECT COUNT(*) AS total FROM item i WHERE {$where}", $params];
    }

    /**
     * Predicado compartido. Devuelve [sqlWhere, params] sobre el alias `i`.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function filter(): array
    {
        $where  = ['i.companyid = ?', 'i.itemstatus = 1', 'i.itemtrackinventory = true'];
        $params = [$this->companyId];

        if (!$this->includeZeroStock) {
            if ($this->locationId !== null) {
                // context/52 (D4) — "tiene movimientos en ESTE depósito" sale
                // del ledger, que ya lleva `locationid` por fila; `tolocation`
                // ya no se escribe, así que preguntarle habría dejado el
                // conteo por depósito vacío. Mismo predicado que la rama de
                // sucursal, un filtro más abajo.
                $where[]  = 'EXISTS (SELECT 1 FROM stock sx'
                          . Inventory::ledgerLocationJoin('sx', 'dlx')
                          . 'WHERE sx.outletid = ? AND '
                          . Inventory::ledgerLocationId('sx', 'dlx')
                          . ' = ? AND sx.itemid = i.itemid)';
                $params[] = $this->outletId;
                $params[] = $this->locationId;
            } else {
                $where[]  = 'EXISTS (SELECT 1 FROM stock sx
                                      WHERE sx.outletid = ? AND sx.itemid = i.itemid)';
                $params[] = $this->outletId;
            }
        }

        if ($this->categoryIds !== []) {
            // Un placeholder por UUID, dos veces: la FK legacy y el m2m. PDO
            // no expande arrays, así que `= ANY(?)` no es una opción — mismo
            // patrón que StockAdjustmentService/StockTransferService.
            $ph       = implode(',', array_fill(0, count($this->categoryIds), '?'));
            $where[]  = "(i.categoryid IN ({$ph})
                          OR EXISTS (SELECT 1 FROM item_category icx
                                      WHERE icx.itemid = i.itemid
                                        AND icx.categoryid IN ({$ph})))";
            $params   = array_merge($params, $this->categoryIds, $this->categoryIds);
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Deduplica y verifica que TODAS las categorías pedidas sean del tenant.
     *
     * Mira `category` (tabla dedicada, mig 21) y `taxonomy` con
     * `taxonomytype='category'`: comparten UUID y se sincronizan por trigger,
     * pero el panel nuevo escribe en la primera y el POS legacy en la
     * segunda — aceptar solo una rechazaría categorías legítimas si el
     * dual-write quedó a medias en algún tenant.
     *
     * @param  string[] $categoryIds
     * @return string[]
     */
    private static function assertCategoriesOwned(string $companyId, array $categoryIds): array
    {
        // Lowercase: PG normaliza el tipo `uuid` a minúsculas al devolverlo, y
        // el array_diff de abajo compara STRINGS — un UUID que llegue en
        // mayúsculas se vería como "no encontrado" y rechazaría una categoría
        // legítima. La comparación dentro del SQL (`IN`) no tiene el problema,
        // porque ahí PG castea a uuid.
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($v) => strtolower(trim((string) $v)), $categoryIds),
            static fn (string $v) => $v !== ''
        )));

        if ($ids === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rs = ncmExecute(
            "SELECT categoryid AS id FROM category
              WHERE companyid = ? AND categoryid IN ({$ph})
             UNION
             SELECT taxonomyid AS id FROM taxonomy
              WHERE companyid = ? AND taxonomytype = 'category' AND taxonomyid IN ({$ph})",
            array_merge([$companyId], $ids, [$companyId], $ids),
            false,
            true
        );

        $found = [];
        if ($rs) {
            while (!$rs->EOF) {
                $found[] = (string) $rs->fields['id'];
                $rs->MoveNext();
            }
        }

        $missing = array_diff($ids, $found);
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'categoryIds inválidos para este tenant: ' . implode(', ', $missing)
            );
        }

        return $ids;
    }
}
