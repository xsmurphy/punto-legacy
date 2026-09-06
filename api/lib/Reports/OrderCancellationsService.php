<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Orders\OrderCancellationQuery;

/**
 * Reporte de ANULACIONES DE COMANDA: qué se borró, de qué orden, por qué,
 * cuánto valía y —lo que le da sentido a la feature— QUIÉN lo hizo.
 *
 * Cubre los DOS granos, con una fila por EVENTO y una columna `scope` que los
 * distingue:
 *
 *   - `scope='item'`  — se anuló UNA línea. `itemName`/`qty` traen la línea;
 *     `amount` es qty × precio congelado.
 *   - `scope='order'` — se canceló la ORDEN ENTERA. `itemName`/`qty` son
 *     `null` (no hay una línea que nombrar), `itemCount` dice cuántas se
 *     llevó y `amount` es lo que la orden todavía valía en ese momento.
 *
 * Nació cubriendo solo el grano ítem (`e.scope = 'item'` hardcodeado en el
 * WHERE). Una orden de ocho líneas cancelada entera era INVISIBLE en el
 * reporte que existe justamente para auditar eso — el agujero más grande, sin
 * una sola fila. Se amplió el reporte en vez de crear uno nuevo por la misma
 * razón por la que hay un solo `OrderCancelGate`: el dueño hace UNA pregunta
 * ("¿cuánto se anuló y quién lo anuló?") y dos pantallas que la contestan a
 * medias se desincronizan a la primera corrección.
 *
 * ── Qué queda acá y qué no ─────────────────────────────────────────────────
 *
 * Acá vive el SCOPE del reporte —rango de fechas del panel y `Roc`— y nada
 * más. Cómo se lee una anulación de `pos_order_event` y cuánto vale (incluida
 * la invariante anti-doble-conteo, que es la parte delicada) está en
 * `Punto\Api\Orders\OrderCancellationQuery`, compartida con los dos bloques
 * del cierre de caja. Ver ese docblock antes de tocar la cuenta.
 *
 * ── Por qué la fuente es el evento y no la entidad ─────────────────────────
 *
 *  1. La entidad solo guarda el estado FINAL. El evento guarda el momento, el
 *     motivo y el actor — o sea, las tres columnas que este reporte existe
 *     para mostrar.
 *  2. La entidad guarda UN estado, y en esta feature hay dos que se pisan: una
 *     línea `pending` de una orden ya CANCELADA se puede anular igual
 *     (`updateItemStatus()` no exige que la orden esté viva), así que leyendo
 *     `status` no habría forma de distinguir "la borró alguien" de "se fue con
 *     su orden". El evento distingue las dos porque son dos filas.
 *
 *     (La versión anterior de este docblock justificaba lo mismo diciendo que
 *     `ITEM_TRANSITIONS` permite volver de `cancelled`. Es FALSO —
 *     `'cancelled' => []` es terminal, igual que en `ORDER_TRANSITIONS`— y la
 *     invariante anti-doble-conteo de `OrderCancellationQuery` de hecho se
 *     apoya en que NO haya reactivación. Se corrige acá para que nadie la
 *     vuelva a citar como si fuera cierta.)
 *  3. El reporte cuenta EVENTOS y no entidades, que es lo que el encargado
 *     quiere auditar: la pregunta es "¿cuántas veces se anuló algo y quién?",
 *     no "¿cuántas cosas están anuladas ahora?".
 */
final class OrderCancellationsService
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
        $where  = OrderCancellationQuery::WHERE_IS_CANCELLATION
                . ' AND e.created_at BETWEEN ? AND ?'
                . $roc;
        $params = [$from, $to];

        return [
            'rows'   => OrderCancellationQuery::rows($where, $params, self::ROW_LIMIT),
            'totals' => OrderCancellationQuery::totals($where, $params),
        ];
    }
}
