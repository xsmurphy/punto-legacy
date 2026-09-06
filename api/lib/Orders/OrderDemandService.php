<?php
declare(strict_types=1);

namespace Punto\Api\Orders;

use Punto\Api\Support\TenantClock;

/**
 * OrderDemandService — LO QUE FALTA COCINAR, agregado por producto
 * (context/70-viandas.md, etapa B: "un clic del lote de pedidos al lote de
 * producción").
 *
 * ── Qué resuelve ────────────────────────────────────────────────────────────
 *
 * En el KDS entran N pedidos y muchos platos comparten insumos. Hoy cada
 * comanda dice lo suyo —una 100 g de pollo, otra 150 g— y nadie suma. La
 * pregunta del negocio es macro: *"para toda la cola, ¿cuánto de cada cosa?"*.
 *
 * La matemática de insumos ya existe (`Inventory::explodeBatch()` +
 * `ProductionBatchService::estimate()`). Lo que faltaba era el ALIMENTADOR:
 * convertir la cola de órdenes en las `{plato, cantidad}` que el lote come.
 * Esta clase es exactamente eso y NADA más — no explota recetas, no mira
 * stock, no escribe. Devuelve demanda por PRODUCTO; la explosión a insumos la
 * sigue haciendo el motor de siempre, que es el mismo que después consume el
 * stock. Duplicar acá la agregación por insumo sería una segunda definición de
 * "qué consume esta producción".
 *
 * ── Es una FOTO, no un vivo (D2) ────────────────────────────────────────────
 *
 * El resultado describe la cola en el INSTANTE de la consulta y viene con su
 * `takenAt`. Un pedido que entra después NO muta un lote ya armado: el
 * operador vuelve a traer si quiere. Por eso no hay ninguna suscripción
 * realtime colgada de esto, y por eso `takenAt` viaja en el payload en vez de
 * dejar que el cliente ponga su propio reloj — la hora que el cocinero lee
 * tiene que ser la del comercio (`TenantClock`), no la de la laptop.
 *
 * ── Qué cuenta y qué no (D1) ────────────────────────────────────────────────
 *
 *  - Ítem `pending` o `preparing`: SUMA. Es lo que todavía hay que cocinar.
 *  - Ítem `ready` o `delivered`: NO suma — ya se cocinó. Traerlo obligaría a
 *    cocinar de nuevo lo que está en el pase.
 *  - Ítem `cancelled`: NO suma.
 *  - Orden `closed`, `cancelled` o `delivered`: NO suma, cualquiera sea el
 *    estado de sus líneas. Una orden cancelada puede quedar con líneas en
 *    `pending` (cancelar la ORDEN toca `pos_order.status`, no cascadea a los
 *    ítems), así que el filtro de cabecera no es redundante con el de línea:
 *    es el que evita cocinar un pedido que ya no existe.
 *
 * ── Las hijas de add-on SÍ entran, como líneas propias ───────────────────────
 *
 * El queso extra es una necesidad real de producción y tiene su propio
 * `itemid`. Se incluyen sin caso especial, y filtrar por el status de la LÍNEA
 * alcanza: una hija espeja el status de su padre en la BD, no solo en la UI —
 * `OrderCoreService::updateItemStatus()` mueve padre e hijas en el MISMO
 * UPDATE (`WHERE orderitemid = ? OR parentorderitemid = ?`,
 * `OrderCoreService.php:1132-1137`), y nacen `pending` igual que él. Verificado
 * contra el código, no asumido desde `context/modules/11` regla 2 (que
 * describe la restricción de la UI: una hija no se mueve sola).
 *
 * Ojo con la plata: las hijas van con `price = 0` (el recargo ya está dentro
 * del `price` del padre, ver mig 140). Acá no se toca un solo importe —
 * la demanda de producción es CANTIDAD, no dinero — así que esa invariante
 * ni se roza.
 *
 * ── Líneas sin `itemid` ─────────────────────────────────────────────────────
 *
 * Una línea de texto libre ("Milanesa como siempre") no tiene producto de
 * catálogo y por lo tanto no puede ser línea de un lote: no hay receta que
 * explotar ni stock que acreditar. Se excluyen, pero se CUENTAN y se devuelve
 * el número. Esconderlas dejaría al cocinero creyendo que la pantalla trajo
 * toda la cola.
 */
final class OrderDemandService
{
    /**
     * Cuántos pares (producto, orden) se traen como máximo. Es el grano de la
     * query agregada, no de órdenes: 400 pedidos de 5 platos son 2000 filas.
     * Si se toca el techo se devuelve `truncated => true` en vez de mentir con
     * un total corto.
     *
     * El orden es ALFABÉTICO por producto, no cronológico, porque el consumidor
     * es una lista que se lee con el ojo. Consecuencia asumida: si alguna vez
     * se tocara el techo, lo que se pierde son los productos del final del
     * abecedario, no los pedidos más nuevos. Es aceptable porque a este techo
     * no se llega con una cola de cocina real (son ~500 pedidos abiertos a la
     * vez) y porque el caso viene DECLARADO con `truncated`, no escondido. Si
     * un día se llegara, la respuesta correcta es paginar o acotar por fecha
     * —no reordenar y seguir cortando.
     */
    private const MAX_PAIRS = 5000;

    /** Estados de LÍNEA que todavía representan trabajo de cocina. */
    private const OPEN_ITEM_STATUSES = ['pending', 'preparing'];

    /** Estados de ORDEN que ya no producen nada. */
    private const TERMINAL_ORDER_STATUSES = ['closed', 'cancelled', 'delivered'];

    /**
     * La cola pendiente de una sucursal, agregada por producto.
     *
     * Lectura pura, una sola query. La agregación por (producto, orden) la
     * hace Postgres —no un N+1 por orden— y el pliegue a total por producto es
     * un `foreach` sobre esas filas ya agregadas.
     *
     * @return array{
     *   outletId: string,
     *   takenAt: string,
     *   orderCount: int,
     *   skippedFreeText: int,
     *   truncated: bool,
     *   lines: list<array{
     *     itemId: string,
     *     itemName: string,
     *     qty: float,
     *     sources: list<array{orderId:string, orderNumber:int|null, qty:float}>
     *   }>
     * }
     */
    public function pendingByItem(string $companyId, string $outletId): array
    {
        if ($outletId === '') {
            throw new \InvalidArgumentException('outletId requerido');
        }
        $outlet = ncmExecute(
            'SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1',
            [$outletId, $companyId]
        );
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        $itemStatuses  = self::OPEN_ITEM_STATUSES;
        $orderStatuses = self::TERMINAL_ORDER_STATUSES;
        $itemMarks     = implode(',', array_fill(0, count($itemStatuses), '?'));
        $orderMarks    = implode(',', array_fill(0, count($orderStatuses), '?'));

        // `pos_order`/`pos_order_item` son lowercase SIN comillas (mig 79).
        //
        // El plan entra por `idx_pos_order_company_outlet_status
        // (companyid, outletid, status)` y salta a las líneas por
        // `idx_pos_order_item_order (orderid)`: ambos ya existen, no hace
        // falta migración.
        //
        // El nombre sale del catálogo VIGENTE y cae al snapshot de la línea
        // (`oi.name`) solo si el producto se borró: lo que se está armando es
        // un lote contra el catálogo de hoy, y el picker de la pantalla del
        // lote muestra ese mismo nombre. `MIN(...)` lo hace determinístico
        // cuando dos líneas del mismo producto tienen snapshots distintos
        // (un renombre entre pedido y pedido).
        $sql = "
            SELECT oi.itemid                            AS itemid,
                   o.orderid                            AS orderid,
                   MIN(o.ordernumber)                   AS ordernumber,
                   MIN(COALESCE(it.itemname, oi.name))  AS itemname,
                   SUM(oi.qty)                          AS qty
              FROM pos_order o
              JOIN pos_order_item oi
                ON oi.orderid = o.orderid
               AND oi.companyid = o.companyid
              LEFT JOIN item it
                ON it.itemid = oi.itemid
               AND it.companyid = oi.companyid
             WHERE o.companyid = ?
               AND o.outletid  = ?
               AND o.status NOT IN ($orderMarks)
               AND oi.status IN ($itemMarks)
               AND oi.itemid IS NOT NULL
             GROUP BY oi.itemid, o.orderid
             ORDER BY MIN(COALESCE(it.itemname, oi.name)) ASC, MIN(o.ordernumber) ASC NULLS LAST
             LIMIT " . (self::MAX_PAIRS + 1);

        $params = array_merge([$companyId, $outletId], $orderStatuses, $itemStatuses);
        $rows   = ncmRows($sql, $params);

        $truncated = count($rows) > self::MAX_PAIRS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_PAIRS);
        }

        /** @var array<string,array{itemId:string,itemName:string,qty:float,sources:list<array{orderId:string,orderNumber:int|null,qty:float}>}> $byItem */
        $byItem = [];
        $orders = [];

        foreach ($rows as $row) {
            $itemId  = (string) ($row['itemid'] ?? '');
            $orderId = (string) ($row['orderid'] ?? '');
            $qty     = (float) ($row['qty'] ?? 0);
            if ($itemId === '' || $qty <= 0) {
                continue;
            }
            $orders[$orderId] = true;

            if (!isset($byItem[$itemId])) {
                $byItem[$itemId] = [
                    'itemId'   => $itemId,
                    'itemName' => (string) ($row['itemname'] ?? ''),
                    'qty'      => 0.0,
                    'sources'  => [],
                ];
            }
            $byItem[$itemId]['qty']      += $qty;
            $byItem[$itemId]['sources'][] = [
                'orderId'     => $orderId,
                // NULL es un caso real: `pos_order.ordernumber` es nullable y
                // las órdenes viejas (pre mig 129) pueden no tenerlo. La UI
                // muestra el id corto en ese caso, no un "#0" inventado.
                'orderNumber' => isset($row['ordernumber']) && $row['ordernumber'] !== null
                    ? (int) $row['ordernumber']
                    : null,
                'qty'         => $qty,
            ];
        }

        return [
            'outletId'        => $outletId,
            'takenAt'         => TenantClock::now($companyId),
            'orderCount'      => count($orders),
            'skippedFreeText' => $this->countFreeTextLines($companyId, $outletId),
            'truncated'       => $truncated,
            'lines'           => array_values($byItem),
        ];
    }

    /**
     * Cuántas líneas de la cola quedaron afuera por no tener producto de
     * catálogo. Query aparte y no un `COUNT(*) FILTER` en la principal a
     * propósito: la principal agrupa por `oi.itemid`, y las filas con
     * `itemid IS NULL` colapsarían todas en un grupo que además rompería el
     * pliegue. Es un COUNT sobre los mismos índices, no un N+1.
     */
    private function countFreeTextLines(string $companyId, string $outletId): int
    {
        $itemMarks  = implode(',', array_fill(0, count(self::OPEN_ITEM_STATUSES), '?'));
        $orderMarks = implode(',', array_fill(0, count(self::TERMINAL_ORDER_STATUSES), '?'));

        $params = array_merge(
            [$companyId, $outletId],
            self::TERMINAL_ORDER_STATUSES,
            self::OPEN_ITEM_STATUSES
        );

        $row = ncmExecute(
            "SELECT COUNT(*) AS n
               FROM pos_order o
               JOIN pos_order_item oi
                 ON oi.orderid = o.orderid
                AND oi.companyid = o.companyid
              WHERE o.companyid = ?
                AND o.outletid  = ?
                AND o.status NOT IN ($orderMarks)
                AND oi.status IN ($itemMarks)
                AND oi.itemid IS NULL",
            $params
        );

        return $row ? (int) ($row['n'] ?? 0) : 0;
    }
}
