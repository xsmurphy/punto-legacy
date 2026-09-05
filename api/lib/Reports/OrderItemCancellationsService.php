<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Reporte de ANULACIONES DE ÍTEMS de comanda: qué línea se borró, de qué
 * orden, por qué, cuánto valía y —lo que le da sentido a la feature— QUIÉN lo
 * hizo.
 *
 * ── La fuente es el evento, no el ítem ─────────────────────────────────────
 *
 * Se lee de `pos_order_event` (`scope='item' AND to_status='cancelled'`) y no
 * de `pos_order_item.status='cancelled'`, aunque lo segundo parezca más
 * directo. Tres razones:
 *
 *  1. El ítem solo guarda el estado FINAL. El evento guarda el momento, el
 *     motivo y el actor — o sea, las tres columnas que este reporte existe
 *     para mostrar.
 *  2. `ITEM_TRANSITIONS` permite volver de `cancelled`. Una línea reactivada
 *     desaparecería del reporte, y la anulación igual pasó.
 *  3. La misma línea puede anularse más de una vez. El reporte cuenta EVENTOS,
 *     que es lo que el encargado quiere auditar.
 *
 * ── El nombre del actor ────────────────────────────────────────────────────
 *
 * `actor_id` es un `contactid` cuando `actor_kind='user'` y un `deviceid`
 * cuando es `'device'`. El LEFT JOIN a `contact` se hace SIN condicionar por
 * kind y acarreando `companyid`: un deviceid nunca va a matchear un contactid,
 * así que el join devuelve NULL solo para esas filas — que es exactamente lo
 * que el contrato pide (`actorName: null`). Las filas `device` son las
 * anteriores al arreglo de atribución de `OrderCoreService::recordEvent()`, o
 * las de un KDS operando sin operador identificado.
 *
 * Postgres: `pos_order_event`, `pos_order_item`, `pos_order`, `space_session` y
 * `space` son lowercase sin comillas (patrón migs 79/80/85). `contact` y
 * `company` llevan columnas camelCase, pero PG las resuelve igual sin comillas
 * porque las plegó a minúsculas al crearlas (memoria
 * `project_pg_identifier_casing`); se escriben como las escribe el resto del
 * módulo de órdenes.
 */
final class OrderItemCancellationsService
{
    /**
     * Tope duro de filas. Mismo criterio que `OrderCoreService::list()`: un
     * rango largo en un comercio con mucho movimiento no puede devolver un
     * JSON sin límite. Los TOTALES se calculan aparte, sobre el rango COMPLETO,
     * para que el resumen no mienta cuando el detalle se corta.
     */
    private const ROW_LIMIT = 500;

    /**
     * @param string $from  naive tenant-local 'Y-m-d H:i:s' (Date::reportRange)
     * @param string $to    idem
     * @param string $roc   fragmento " AND e.companyId = '…' [AND e.outletId …]"
     *                      — se espera construido con `Roc::build($cid, $oid, 'e')`
     * @return array{rows: list<array<string,mixed>>, totals: array{count:int, amount:float}}
     */
    public function report(string $from, string $to, string $roc): array
    {
        // El `$roc` ya viene con el alias `e.` puesto — el endpoint llama a
        // `Roc::build(..., 'e')`, que es el tercer parámetro que ese helper
        // expone justamente para las queries con JOIN. Nada de `str_replace`
        // sobre el fragmento acá.
        //
        // Que el scope de sucursal cuelgue de `e.outletid` y no de `o.outletid`
        // es deliberado: `recordEvent()` estampa el outlet de la orden en el
        // evento, así que son el mismo valor, y filtrar por la columna del
        // evento deja el WHERE sobre el índice
        // `idx_pos_order_event_outlet (companyid, outletid, created_at DESC)`,
        // que es exactamente el orden de este reporte.
        $where = "e.scope = 'item'
                    AND e.to_status = 'cancelled'
                    AND e.created_at BETWEEN ? AND ?"
               . $roc;

        $rows = ncmRows(
            "SELECT e.eventid,
                    e.created_at,
                    e.orderid,
                    e.reason,
                    e.actor_kind,
                    o.ordernumber,
                    sp.name        AS space_name,
                    oi.name        AS item_name,
                    oi.qty         AS item_qty,
                    oi.price       AS item_price,
                    c.contactname  AS actor_name
               FROM pos_order_event e
               JOIN pos_order_item oi
                 ON oi.orderitemid = e.orderitemid
                AND oi.companyid   = e.companyid
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
                AND sp.companyid = ss.companyid
              WHERE {$where}
              ORDER BY e.created_at DESC
              LIMIT " . self::ROW_LIMIT,
            [$from, $to]
        );

        $out = [];
        foreach ($rows as $r) {
            $qty   = (float) ($r['item_qty'] ?? 0);
            // `price` es NULL-able en `pos_order_item` (una línea hija de add-on
            // lleva 0, y una línea vieja puede no tener precio). NULL vale 0:
            // el reporte suma plata, y "no sé cuánto valía" no es un monto.
            $price = $r['item_price'] !== null ? (float) $r['item_price'] : 0.0;

            $out[] = [
                'eventId'    => (string) ($r['eventid'] ?? ''),
                'at'         => (string) ($r['created_at'] ?? ''),
                'orderId'    => (string) ($r['orderid'] ?? ''),
                'orderNumber'=> isset($r['ordernumber']) && $r['ordernumber'] !== null ? (int) $r['ordernumber'] : null,
                'spaceName'  => $r['space_name'] !== null ? (string) $r['space_name'] : null,
                'itemName'   => (string) ($r['item_name'] ?? ''),
                'qty'        => $qty,
                'amount'     => $qty * $price,
                'reason'     => $r['reason'] !== null && (string) $r['reason'] !== '' ? (string) $r['reason'] : null,
                'actorName'  => $r['actor_name'] !== null ? (string) $r['actor_name'] : null,
                'actorKind'  => (string) ($r['actor_kind'] ?? 'system'),
            ];
        }

        return ['rows' => $out, 'totals' => $this->totals($where, [$from, $to])];
    }

    /**
     * Conteo y monto sobre el rango COMPLETO — sin el LIMIT del detalle.
     *
     * El monto se calcula en SQL con el mismo criterio que arriba
     * (`COALESCE(price, 0)`): un total que sumara solo las 500 filas
     * devueltas diría un número más chico que el real y nadie se enteraría.
     *
     * @param list<string> $params
     * @return array{count:int, amount:float}
     */
    private function totals(string $where, array $params): array
    {
        $row = ncmExecute(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(oi.qty * COALESCE(oi.price, 0)), 0) AS amt
               FROM pos_order_event e
               JOIN pos_order_item oi
                 ON oi.orderitemid = e.orderitemid
                AND oi.companyid   = e.companyid
               JOIN pos_order o
                 ON o.orderid   = e.orderid
                AND o.companyid = e.companyid
              WHERE {$where}",
            $params,
            false
        );

        return [
            'count'  => (int) ($row['cnt'] ?? 0),
            'amount' => (float) ($row['amt'] ?? 0),
        ];
    }
}
