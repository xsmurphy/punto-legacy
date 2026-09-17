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
 *
 * ── Un RANGO de fechas por lote (context/79 D2, ampliado 2026-09-17) ────────
 *
 * Desde que la orden puede tener fecha de entrega (`pos_order.scheduled_for`,
 * mig 225), la cola deja de ser una sola: la del viernes no es la de hoy. El
 * par `from`/`to` elige cuáles se traen —un solo día es `from == to`— y la
 * regla del D2 NO es simétrica; lo que la generaliza es el ARRANQUE del rango,
 * no cada día suelto:
 *
 *  - ARRANCA HOY O ANTES (o sin fechas, que es lo mismo que hoy-hoy): trae lo
 *    del rango MÁS las sin fecha —"para ahora" es todo lo que existía antes de
 *    la mig 225— MÁS las VENCIDAS no producidas. Un pedido de ayer que nadie
 *    cocinó sigue siendo trabajo pendiente: desaparecer de la pantalla no lo
 *    cocina. Por eso el corte de abajo es `scheduled_for::date <= to` y no un
 *    BETWEEN: el piso del rango no recorta nada hacia atrás.
 *  - ARRANCA EN EL FUTURO: solo `BETWEEN from AND to`, sin las sin fecha.
 *    Quien pide "de lunes a viernes" está armando la producción de esa semana;
 *    sumarle la cola suelta de hoy haría un lote que no es de ningún día y
 *    rompería lo único que lo hace auditable ("este lote es la producción de
 *    esa semana").
 *
 * Que el rango de HOY al viernes incluya lo vencido y lo sin fecha no es un
 * efecto colateral: es la misma frase del D2 aplicada a un rango. "Mi semana"
 * arranca hoy, y lo que quedó sin cocinar de ayer es trabajo de esta semana.
 *
 * El día se corta en la zona del COMERCIO: `scheduled_for::date` sale en hora
 * del tenant sin `AT TIME ZONE` explícito porque `TenantClock::apply()` fija
 * la zona de la sesión de Postgres en el embudo de auth (context/67).
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
     * @param ?string $from Primer día de entrega a traer (`YYYY-MM-DD`). null = hoy.
     * @param ?string $to   Último día del rango. null = el mismo que `$from`,
     *                      o sea un solo día.
     *
     * @return array{
     *   outletId: string,
     *   dateFrom: string,
     *   dateTo: string,
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
    public function pendingByItem(string $companyId, string $outletId, ?string $from = null, ?string $to = null): array
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

        $today                  = substr(TenantClock::now($companyId), 0, 10);
        [$dateFrom, $dateTo]    = self::normalizeRange($from, $to, $today);
        [$dateSql, $dateParams] = self::scheduledFilter($dateFrom, $dateTo, $today);

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
               AND $dateSql
             GROUP BY oi.itemid, o.orderid
             ORDER BY MIN(COALESCE(it.itemname, oi.name)) ASC, MIN(o.ordernumber) ASC NULLS LAST
             LIMIT " . (self::MAX_PAIRS + 1);

        $params = array_merge([$companyId, $outletId], $orderStatuses, $itemStatuses, $dateParams);
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
            // El rango que se está trayendo viaja en el payload por el mismo
            // motivo que `takenAt` (D2): el que lee la pantalla tiene que poder
            // ver DE QUÉ es el lote que armó, y resolverlo de nuevo en el
            // cliente sería una segunda definición de "hoy" contra el reloj de
            // la laptop en vez del del comercio.
            'dateFrom'        => $dateFrom,
            'dateTo'          => $dateTo,
            'takenAt'         => TenantClock::now($companyId),
            'orderCount'      => count($orders),
            'skippedFreeText' => $this->countFreeTextLines($companyId, $outletId, $dateFrom, $dateTo, $today),
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
    private function countFreeTextLines(string $companyId, string $outletId, string $from, string $to, string $today): int
    {
        $itemMarks  = implode(',', array_fill(0, count(self::OPEN_ITEM_STATUSES), '?'));
        $orderMarks = implode(',', array_fill(0, count(self::TERMINAL_ORDER_STATUSES), '?'));
        // El MISMO recorte de fecha que la query principal: si contara la cola
        // entera, el lote del viernes avisaría de líneas sueltas que no son de
        // ese lote y el cocinero iría a buscar un pedido que no existe.
        [$dateSql, $dateParams] = self::scheduledFilter($from, $to, $today);

        $params = array_merge(
            [$companyId, $outletId],
            self::TERMINAL_ORDER_STATUSES,
            self::OPEN_ITEM_STATUSES,
            $dateParams
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
                AND oi.itemid IS NULL
                AND $dateSql",
            $params
        );

        return $row ? (int) ($row['n'] ?? 0) : 0;
    }

    /**
     * El predicado de fecha de entrega, UNA sola definición para las dos
     * queries de esta clase. La asimetría —la decide el ARRANQUE del rango, no
     * cada día— está explicada en el docblock de la clase (D2 de context/79,
     * generalizado a rango el 2026-09-17).
     *
     * Matriz de casos, con `hoy` = 17:
     *
     *  | from | to | SQL                              | qué entra                     |
     *  |------|----|----------------------------------|-------------------------------|
     *  | 17   | 17 | NULL OR <= 17                    | hoy + sin fecha + vencidas    |
     *  | 17   | 19 | NULL OR <= 19                    | hoy..19 + sin fecha + vencidas|
     *  | 15   | 19 | NULL OR <= 19                    | ídem (el piso no recorta)     |
     *  | 18   | 18 | BETWEEN 18 AND 18                | solo el 18                    |
     *  | 18   | 24 | BETWEEN 18 AND 24                | solo esa semana               |
     *
     * @return array{0:string, 1:list<string>} SQL y sus binds, en ese orden.
     */
    private static function scheduledFilter(string $from, string $to, string $today): array
    {
        if ($from <= $today) {
            // Las sin fecha ("para ahora") y las vencidas no producidas entran
            // con las del rango: las tres son trabajo pendiente ahora mismo.
            // Comparación de strings `YYYY-MM-DD`: el formato es lexicográfico,
            // ya validado por `normalizeRange()`.
            return ['(o.scheduled_for IS NULL OR o.scheduled_for::date <= ?::date)', [$to]];
        }

        return ['o.scheduled_for::date BETWEEN ?::date AND ?::date', [$from, $to]];
    }

    /**
     * El rango efectivo. Sin fechas es hoy-hoy (el comportamiento de siempre);
     * con una sola, ese día solo. Un rango al revés se rechaza en vez de
     * devolver vacío en silencio: "del viernes al lunes" es un pedido mal
     * armado, y una cola vacía se leería como "no hay nada que cocinar".
     *
     * @return array{0:string, 1:string}
     */
    private static function normalizeRange(?string $from, ?string $to, string $today): array
    {
        $f = self::normalizeDate($from);
        $t = self::normalizeDate($to);

        $f ??= $t ?? $today;
        $t ??= $f;

        if ($f > $t) {
            throw new \InvalidArgumentException('El rango de fechas está invertido');
        }
        return [$f, $t];
    }

    /** `YYYY-MM-DD` o null. Cualquier otra cosa es un pedido mal armado. */
    private static function normalizeDate(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }
        $value = trim($date);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Fecha inválida (esperado AAAA-MM-DD)');
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('Fecha inválida (esperado AAAA-MM-DD)');
        }
        return $value;
    }
}
