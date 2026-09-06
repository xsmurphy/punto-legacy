<?php
declare(strict_types=1);

namespace Punto\Api\Orders;

/**
 * Cómo se lee una ANULACIÓN DE COMANDA de `pos_order_event`, y cuánta plata
 * representa. Definición ÚNICA, compartida por sus tres consumidores:
 *
 *   1. `Reports\OrderCancellationsService` — el reporte por rango del panel.
 *   2. `Services\DrawerService`            — el bloque del cierre de caja EN VIVO.
 *   3. `Reports\DrawersService`            — el mismo bloque en el histórico del panel.
 *
 * Vive suelta y no dentro del reporte porque los tres tienen que dar el MISMO
 * número. El histórico y el vivo del cierre de caja ya son implementaciones
 * paralelas de la misma cuenta (`context/modules/14-caja.md` regla 10) y ese
 * doc advierte que divergen apenas alguien toca una sola; agregarles una
 * tercera fórmula copiada del reporte habría sido garantizar la divergencia
 * desde el día uno. Acá el WHERE lo arma cada caller —cada uno scopea distinto:
 * `Roc` interpolado el reporte, binds el cierre— y el CÁLCULO es de esta clase.
 *
 * ── LA INVARIANTE ANTI-DOBLE-CONTEO ────────────────────────────────────────
 *
 * Con dos granos de anulación en la misma tabla (una línea suelta y la orden
 * entera), sumar todos los eventos diría que se anuló más plata de la que la
 * orden valía: una comanda a la que le sacaron tres líneas y después
 * cancelaron entera generaría cuatro eventos sobre la misma mercadería.
 *
 * **Cada línea aporta su plata UNA sola vez, a la anulación más TEMPRANA que
 * la cubre.** No es un desempate arbitrario — es la definición honesta de
 * "cuánta plata dejó de cobrarse". Se aplica en las dos direcciones:
 *
 *   - Una fila `order` suma solo las líneas que NO tenían ya un evento de
 *     anulación propio ANTERIOR a la cancelación de la orden. Las otras ya
 *     habían salido del total de la orden.
 *   - Una fila `item` cuya ORDEN ya estaba cancelada antes vale 0: si la orden
 *     ya no existía, la línea ya no valía nada, y su plata la contó la fila de
 *     la orden. Es alcanzable, no teórico — `updateItemStatus()` no exige que
 *     la orden esté viva, y un ítem `pending` de una orden cancelada conserva
 *     su transición legal a `cancelled`.
 *
 * Los dos predicados comparan con `<` estricto contra `e.created_at`, así que
 * un empate exacto al microsegundo —imposible entre dos requests distintos—
 * caería del lado permisivo, no del que duplica.
 *
 * Se DERIVA en la query en vez de persistir una columna `amount` en
 * `pos_order_event` a propósito: derivarlo hace que el número diga la verdad
 * también sobre las cancelaciones anteriores a este cambio, que son todas las
 * que hay hoy en producción. Una columna nueva solo se llenaría de acá en
 * adelante y dejaría el histórico en `null`.
 *
 * ── Add-ons ────────────────────────────────────────────────────────────────
 *
 * El grano `order` cuenta y suma SOLO líneas de primer nivel
 * (`parentorderitemid IS NULL`). Las hijas de add-on llevan `price = 0` —el
 * recargo vive en el padre, `context/modules/11` regla 3— así que no cambian
 * el monto, pero sí inflarían el conteo: "Orden completa (7 ítems)" cuando el
 * cliente pidió tres platos con guarnición no describe nada.
 *
 * Postgres: `pos_order_event`, `pos_order_item` y `pos_order` son lowercase sin
 * comillas (patrón migs 79/80/85).
 */
final class OrderCancellationQuery
{
    /**
     * Filtro base de "esto es una anulación". Los callers lo concatenan con su
     * propio scope y su propio rango de fechas.
     *
     * `to_status = 'cancelled'` es el predicado del índice parcial
     * `idx_pos_order_event_cancel` (mig 200). El `scope IN` que sigue es hoy
     * redundante —el CHECK de la mig 85 solo admite esos dos valores— pero se
     * escribe igual para que un scope futuro no entre a estas cuentas sin que
     * nadie lo decida. Postgres lo resuelve como filtro sobre las filas del
     * índice; no impide usarlo.
     */
    public const WHERE_IS_CANCELLATION = "e.to_status = 'cancelled' AND e.scope IN ('item','order')";

    /**
     * Lo que valía la orden al momento de ESTE evento — sus líneas de primer
     * nivel que todavía no tenían una anulación propia anterior.
     *
     * `LEFT JOIN LATERAL ... ON TRUE` y no dos subselects por columna, para no
     * recorrer `pos_order_item` dos veces: monto y conteo salen del mismo
     * escaneo. El `e.scope = 'order'` de adentro es lo que hace que, para una
     * fila de ítem, el agregado corra sobre el conjunto vacío y devuelva 0/0
     * sin tocar la tabla.
     */
    public const ORDER_AGG_LATERAL = "
          LEFT JOIN LATERAL (
                SELECT COUNT(*)                                           AS n,
                       COALESCE(SUM(oi2.qty * COALESCE(oi2.price, 0)), 0) AS amt
                  FROM pos_order_item oi2
                 WHERE e.scope = 'order'
                   AND oi2.orderid   = e.orderid
                   AND oi2.companyid = e.companyid
                   AND oi2.parentorderitemid IS NULL
                   AND NOT EXISTS (
                         SELECT 1 FROM pos_order_event ce
                          WHERE ce.orderitemid = oi2.orderitemid
                            AND ce.companyid   = oi2.companyid
                            AND ce.scope       = 'item'
                            AND ce.to_status   = 'cancelled'
                            AND ce.created_at  < e.created_at
                       )
               ) oagg ON TRUE";

    /** El LEFT JOIN a la línea. LEFT y no JOIN: un evento de ORDEN tiene
     *  `orderitemid` NULL (mig 85, "NULL = evento de orden") y un JOIN interior
     *  lo descartaba en silencio — era la mitad del agujero que esto cierra. */
    public const ITEM_JOIN = "
          LEFT JOIN pos_order_item oi
                 ON oi.orderitemid = e.orderitemid
                AND oi.companyid   = e.companyid";

    /**
     * Monto de la fila, con la invariante aplicada. Se escribe UNA vez y la
     * usan el detalle, los totales y los dos bloques del cierre — si el detalle
     * y el total divergieran, la suma de lo que se ve en pantalla no daría el
     * número de arriba y nadie sabría cuál creer.
     */
    public const AMOUNT_SQL = "CASE
                        WHEN e.scope = 'order' THEN oagg.amt
                        WHEN EXISTS (
                                SELECT 1 FROM pos_order_event oe
                                 WHERE oe.orderid   = e.orderid
                                   AND oe.companyid = e.companyid
                                   AND oe.scope     = 'order'
                                   AND oe.to_status = 'cancelled'
                                   AND oe.created_at < e.created_at
                             ) THEN 0
                        ELSE COALESCE(oi.qty, 0) * COALESCE(oi.price, 0)
                    END";

    /**
     * Conteo de eventos y monto total del conjunto que describe `$where`.
     *
     * @param string $where predicado COMPLETO (incluye `WHERE_IS_CANCELLATION`,
     *                      el rango de fechas y el scope del caller)
     * @param list<mixed> $params binds del `$where`, en orden
     * @return array{count:int, amount:float}
     */
    public static function totals(string $where, array $params): array
    {
        $row = ncmExecute(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(" . self::AMOUNT_SQL . "), 0) AS amt
               FROM pos_order_event e"
                . self::ITEM_JOIN
                . self::ORDER_AGG_LATERAL . "
              WHERE {$where}",
            $params,
            false
        );

        return [
            'count'  => (int) ($row['cnt'] ?? 0),
            'amount' => (float) ($row['amt'] ?? 0),
        ];
    }

    /**
     * Filas de detalle, ya normalizadas al contrato público. UNA sola forma
     * para los tres consumidores: el reporte del panel las pinta en el
     * `<DataTable>`, el cierre de caja las imprime en el ticket, y el
     * histórico las devuelve en el detalle de la caja. Un shape por consumidor
     * habría hecho que "monto anulado" quisiera decir tres cosas parecidas.
     *
     * @param string $where predicado COMPLETO (ver `totals()`)
     * @param list<mixed> $params binds del `$where`, en orden
     * @param int $limit tope duro de filas
     * @return list<array<string,mixed>>
     */
    public static function rows(string $where, array $params, int $limit): array
    {
        $rows = ncmRows(
            "SELECT e.eventid,
                    e.created_at,
                    e.orderid,
                    e.scope,
                    e.reason,
                    e.actor_kind,
                    o.ordernumber,
                    oi.name        AS item_name,
                    oi.qty         AS item_qty,
                    sp.name        AS space_name,
                    c.contactname  AS actor_name,
                    oagg.n         AS order_item_count,
                    " . self::AMOUNT_SQL . " AS row_amount
               FROM pos_order_event e"
                . self::ITEM_JOIN . "
               JOIN pos_order o
                 ON o.orderid   = e.orderid
                AND o.companyid = e.companyid
          LEFT JOIN contact c
                 ON c.contactid = e.actor_id
                AND c.companyid = e.companyid
          LEFT JOIN space_session ss
                 ON ss.sessionid = o.spacesessionid
                AND ss.companyid = o.companyid
          LEFT JOIN space sp
                 ON sp.tableid   = ss.tableid
                AND sp.companyid = ss.companyid"
                . self::ORDER_AGG_LATERAL . "
              WHERE {$where}
              ORDER BY e.created_at DESC
              LIMIT " . (int) $limit,
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $isOrder = (string) ($r['scope'] ?? 'item') === 'order';
            $out[] = [
                'eventId'     => (string) ($r['eventid'] ?? ''),
                'at'          => (string) ($r['created_at'] ?? ''),
                'orderId'     => (string) ($r['orderid'] ?? ''),
                'orderNumber' => isset($r['ordernumber']) && $r['ordernumber'] !== null ? (int) $r['ordernumber'] : null,
                'spaceName'   => isset($r['space_name']) && $r['space_name'] !== null ? (string) $r['space_name'] : null,
                // El grano de la fila. La UI decide con esto si rotula el
                // artículo o "Orden completa (N ítems)" — nunca infiriéndolo de
                // que `itemName` venga vacío, que también le puede pasar a una
                // línea vieja sin nombre.
                'scope'       => $isOrder ? 'order' : 'item',
                // `null` y no "" en el grano orden: no hay una línea que
                // nombrar, y "" se leería como "una línea sin nombre".
                'itemName'    => $isOrder ? null : (string) ($r['item_name'] ?? ''),
                'qty'         => $isOrder ? null : (float) ($r['item_qty'] ?? 0),
                // Cuántas líneas de primer nivel se llevó la cancelación de la
                // orden. `null` en el grano ítem: ahí la cantidad relevante es
                // `qty`, y un 1 acá invitaría a sumar peras con manzanas.
                'itemCount'   => $isOrder ? (int) ($r['order_item_count'] ?? 0) : null,
                'amount'      => (float) ($r['row_amount'] ?? 0),
                'reason'      => $r['reason'] !== null && (string) $r['reason'] !== '' ? (string) $r['reason'] : null,
                'actorName'   => isset($r['actor_name']) && $r['actor_name'] !== null ? (string) $r['actor_name'] : null,
                'actorKind'   => (string) ($r['actor_kind'] ?? 'system'),
            ];
        }

        return $out;
    }
}
