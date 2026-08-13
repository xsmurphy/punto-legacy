<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * Tab "Stock" del detalle de ítem — resumen de valorización, historial de
 * movimientos y ajustes manuales, para UN ítem across todas las sucursales.
 *
 * `stock` es un ledger: cada fila es un movimiento. El saldo se calcula
 * SUMANDO `stockCount`, NO leyendo el `stockOnHand` de la última fila: ese
 * snapshot se desincroniza en cuanto entra un movimiento fechado hacia atrás
 * (una compra cargada con fecha de ayer se inserta en el medio del historial y
 * las filas posteriores conservan su acumulado viejo). Mismo criterio que
 * `Inventory::onHand()`.
 *
 * "Precio de compra" (el otro KPI del tab) NO vive acá — ya existe
 * `GET /v1/items?id=&resource=last-purchase-price` (último precio de compra
 * real, de `itemSold` type=1); se reusa desde el frontend en vez de
 * duplicarlo.
 */
final class StockMovementsService
{
    /**
     * Stock actual + costo promedio ponderado + valor total del ítem, sumando
     * todas las sucursales. Devuelve ceros si el ítem no trackea inventario o
     * nunca tuvo movimientos.
     *
     * @return array{qty:float,avgCost:float,totalValue:float}
     */
    public function summary(string $itemId, string $companyId): array
    {
        // El saldo por sucursal sale de la SUMA de sus movimientos; el costo
        // promedio, de la última fila de esa sucursal (es el acumulado vigente).
        $row = ncmExecute(
            "SELECT COALESCE(SUM(qty), 0) AS qty,
                    COALESCE(SUM(qty * cogs), 0) AS value
               FROM (
                 SELECT s.outletId,
                        SUM(s.stockCount) AS qty,
                        (SELECT s2.stockOnHandCOGS
                           FROM stock s2
                          WHERE s2.itemId = s.itemId AND s2.companyId = s.companyId
                            AND s2.outletId = s.outletId
                          ORDER BY s2.stockDate DESC, s2.stockId DESC
                          LIMIT 1) AS cogs
                   FROM stock s
                  WHERE s.itemId = ? AND s.companyId = ?
                  GROUP BY s.itemId, s.companyId, s.outletId
               ) porSucursal",
            [$itemId, $companyId]
        );

        $qty   = (float) ($row['qty'] ?? 0);
        $value = (float) ($row['value'] ?? 0);

        return [
            'qty'        => $qty,
            // Evitar división por cero cuando el saldo neto es 0 (ej. todo
            // vendido) — el costo promedio no tiene sentido sin stock, 0 es
            // más honesto que un NaN/Infinity serializado.
            'avgCost'    => $qty > 0.0000001 ? $value / $qty : 0.0,
            'totalValue' => $value,
        ];
    }

    /**
     * Cuánto hay y DÓNDE: saldo por sucursal, con el detalle por depósito.
     *
     * Era el dato que faltaba. El tab "Stock" mostraba precio de compra, costo
     * promedio y stock valorizado —tres cifras en dinero— y en ningún lado
     * cuántas unidades hay, que es lo primero que se va a mirar.
     *
     * El depósito (`locationId`) es opcional en el ledger: los movimientos sin
     * depósito son del "principal" de esa sucursal. Se devuelven como una línea
     * más con `locationId = null` en vez de omitirse, porque si no el detalle
     * no suma el total de la sucursal y parece que faltan unidades.
     *
     * @return array{total: float, outlets: list<array<string,mixed>>}
     */
    public function breakdown(string $itemId, string $companyId): array
    {
        $rs = ncmExecute(
            "SELECT s.outletId, o.outletName, s.locationId, l.taxonomyName AS locationName,
                    SUM(s.stockCount) AS qty
               FROM stock s
               JOIN outlet o ON o.outletId = s.outletId
          LEFT JOIN taxonomy l ON l.taxonomyId = s.locationId
              WHERE s.itemId = ? AND s.companyId = ?
              GROUP BY s.outletId, o.outletName, s.locationId, l.taxonomyName
              ORDER BY o.outletName ASC, l.taxonomyName ASC NULLS FIRST",
            [$itemId, $companyId],
            false,
            true
        );

        $porSucursal = [];
        $total       = 0.0;

        if ($rs !== false && is_object($rs)) {
            while (!$rs->EOF) {
                $f        = $rs->fields;
                $outletId = (string) ($f['outletid'] ?? '');
                $qty      = (float) ($f['qty'] ?? 0);

                if (!isset($porSucursal[$outletId])) {
                    $porSucursal[$outletId] = [
                        'outletId'   => $outletId,
                        'outletName' => (string) ($f['outletname'] ?? ''),
                        'qty'        => 0.0,
                        'locations'  => [],
                    ];
                }

                $porSucursal[$outletId]['qty'] += $qty;
                $porSucursal[$outletId]['locations'][] = [
                    'locationId'   => $f['locationid'] ?? null,
                    'locationName' => $f['locationname'] ?? null,
                    'qty'          => $qty,
                ];
                $total += $qty;

                $rs->MoveNext();
            }
            $rs->Close();
        }

        return ['total' => $total, 'outlets' => array_values($porSucursal)];
    }

    /**
     * Historial paginado de movimientos, más recientes primero — todas las
     * sucursales del ítem, no solo la activa (el dueño revisa el ítem desde
     * el panel, no desde una caja con sucursal fija). `limit`/`offset`, no
     * page/pageSize — mismo criterio de paginación que el resto de
     * `api/v1/items.php` (GET lista principal).
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public function movements(string $itemId, string $companyId, int $limit, int $offset): array
    {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $countRow = ncmExecute(
            'SELECT COUNT(*) AS total FROM stock WHERE itemId = ? AND companyId = ?',
            [$itemId, $companyId]
        );
        $total = (int) ($countRow['total'] ?? 0);

        $rs = ncmExecute(
            "SELECT s.stockId, s.stockDate, s.stockSource, s.stockCount, s.stockCOGS,
                    s.stockOnHand, s.stockOnHandCOGS, s.stockNote,
                    o.outletName, c.contactName AS userName,
                    l.taxonomyName AS locationName
               FROM stock s
               JOIN outlet o ON o.outletId = s.outletId
               LEFT JOIN contact c ON c.contactId = s.userId
               LEFT JOIN taxonomy l ON l.taxonomyId = s.locationId
              WHERE s.itemId = ? AND s.companyId = ?
              ORDER BY s.stockDate DESC, s.stockId DESC
              LIMIT ? OFFSET ?",
            [$itemId, $companyId, $limit, $offset],
            false,
            true
        );

        $items = [];
        if ($rs !== false) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $items[] = [
                    'id'             => (string) $f['stockid'],
                    'date'           => $f['stockdate'] ?? null,
                    'source'         => (string) ($f['stocksource'] ?? ''),
                    // stockCount trae el signo (+N / -N) — ver Inventory::manageStock.
                    'delta'          => (float) ($f['stockcount'] ?? 0),
                    'deltaCogs'      => (float) ($f['stockcogs'] ?? 0),
                    'stockOnHand'    => (float) ($f['stockonhand'] ?? 0),
                    'stockOnHandCogs'=> (float) ($f['stockonhandcogs'] ?? 0),
                    'note'           => $f['stocknote'] ?? null,
                    'outletName'     => (string) ($f['outletname'] ?? ''),
                    'userName'       => $f['username'] ?? null,
                    'locationName'   => $f['locationname'] ?? null,
                ];
                $rs->MoveNext();
            }
        }

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * Ajuste manual (+/-) desde el detalle del ítem — UNA sucursal a la vez
     * (a diferencia del ajuste masivo multi-ítem de `StockAdjustmentService`,
     * que ya existe para `/stock-adjustment`; acá se reusa la misma primitiva
     * de dominio, `Inventory::manageStock`, para no bifurcar la lógica de
     * costeo ponderado en dos sitios).
     *
     * @throws \RuntimeException si el ítem no trackea inventario o el ajuste falla.
     */
    public function adjust(
        string $companyId,
        string $userId,
        string $itemId,
        string $outletId,
        ?string $locationId,
        string $type,
        float $qty,
        ?float $unitCost,
        string $reason
    ): array {
        if (!in_array($type, ['+', '-'], true)) {
            throw new \RuntimeException('El tipo de ajuste debe ser + o -.');
        }
        if ($qty <= 0) {
            throw new \RuntimeException('La cantidad debe ser mayor a 0.');
        }
        if (trim($reason) === '') {
            throw new \RuntimeException('El motivo del ajuste es obligatorio.');
        }

        $item = ncmExecute(
            'SELECT itemId, itemTrackInventory FROM item WHERE itemId = ? AND companyId = ? AND itemStatus = 1 LIMIT 1',
            [$itemId, $companyId]
        );
        if (!$item) {
            throw new \RuntimeException('Ítem no encontrado.');
        }
        if (!$item['itemTrackInventory']) {
            throw new \RuntimeException('Este ítem no trackea inventario — activá "Controla stock" primero.');
        }

        $outlet = ncmExecute(
            'SELECT outletId FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',
            [$outletId, $companyId]
        );
        if (!$outlet) {
            throw new \RuntimeException('Sucursal inválida para este comercio.');
        }

        $result = \Punto\App\Domain\Inventory::manageStock([
            'itemId'     => $itemId,
            'source'     => 'adjustment',
            'count'      => $qty,
            'type'       => $type,
            // '' (no numérico) le dice a manageStock "no tengo costo nuevo,
            // usá el COGS ponderado que ya tenía la sucursal" — mismo
            // contrato que StockAdjustmentService::create.
            'cogs'       => $unitCost !== null ? $unitCost : '',
            'userId'     => $userId,
            'outletId'   => $outletId,
            'locationId' => $locationId,
            'note'       => $reason,
            'date'       => date('Y-m-d H:i:s'),
            'companyId'  => $companyId,
        ]);

        if ($result === false) {
            throw new \RuntimeException('No se pudo aplicar el ajuste.');
        }

        realtimePublish('item', 'update', $itemId);

        return $this->summary($itemId, $companyId);
    }
}
