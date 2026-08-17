<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

/**
 * SpaceBalanceService — LA definición de "cuánto debe una mesa"
 * (context/15-espacios-module-plan.md §F3).
 *
 * Existe porque el saldo lo consultan DOS write-paths distintos y ambos
 * deciden plata con él:
 *
 *   - SpaceSettlementService::registerPayment/settleIfCovered — cuánto falta
 *     para aceptar un parcial y cuándo liquidar.
 *   - SpaceSessionService::close — el invariante "la sesión solo se cierra
 *     con saldo ≤ 0".
 *
 * Cuando cada uno tenía su propia query, divergían: `close()` contaba las
 * órdenes ya cobradas (`o.status <> 'cancelled'`) y el settlement no
 * (`NOT IN ('closed','cancelled')`), así que "el saldo" significaba dos cosas
 * distintas según quién preguntara — y el que decidía el cierre necesitaba un
 * carve-out para compensar su propia definición. Una sola clase, una sola
 * definición.
 *
 * Definición:
 *   total   = Σ (qty × price) de ítems NO cancelados de órdenes NO
 *             cerradas/canceladas de la sesión. Una orden `closed` está
 *             cobrada por construcción — `pos_order.status='closed'` lo setea
 *             EXCLUSIVAMENTE markPaid() con su transactionId (ver
 *             OrderCoreService::updateStatus, que prohíbe la transición) — así
 *             que su plata ya entró y no es saldo pendiente.
 *   paid    = Σ `space_session_payment` (ledger de parciales, mig 90).
 *   balance = total − paid.
 *
 * Es el mismo shape que `loadFromSession` en frontend/lib/cart/store.ts: qué
 * cuenta para el total de la mesa no diverge entre front y back.
 */
final class SpaceBalanceService
{
    /**
     * Tolerancia de una unidad mínima de moneda: la última parte de un split
     * absorbe el resto del redondeo (ver SpaceSettlementService::splitShares)
     * y puede diferir en un centavo del balance recalculado.
     */
    public const MONEY_EPSILON = 0.01;

    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Dos queries (ítems, pagos) — el total sale de agregar la query de ítems
     * en PHP, no de una tercera query. `items` alimenta la UI de selección
     * "por ítems" del split.
     *
     * El caller que decide una escritura sobre la sesión DEBE tener la fila de
     * `space_session` bloqueada (`FOR UPDATE`) antes de llamar acá: el saldo
     * leído sin lock es una foto que otra request concurrente ya invalidó.
     *
     * @return array{total:float,paid:float,balance:float,items:list<array<string,mixed>>}
     */
    public function compute(string $companyId, string $sessionId): array
    {
        $itemsRs = $this->db->Execute(
            "SELECT oi.orderitemid, oi.orderid, oi.name, oi.qty, oi.price, oi.settledpaymentid
               FROM pos_order_item oi
               JOIN pos_order o ON o.orderid = oi.orderid AND o.companyid = oi.companyid
              WHERE o.spacesessionid = ? AND o.companyid = ?
                AND o.status NOT IN ('closed','cancelled')
                AND oi.status != 'cancelled'
                -- Las líneas hijas de add-ons (context/41, mig 139) NO son
                -- unidades cobrables: no llevan plata propia (price = 0, su
                -- recargo ya está en el precio del padre) y no se pagan por
                -- separado — el queso extra se cobra con la hamburguesa. Sin
                -- este filtro, el split por ítems listaría filas de $0
                -- seleccionables que no mueven el saldo.
                AND oi.parentorderitemid IS NULL
              ORDER BY oi.created_at ASC",
            [$sessionId, $companyId]
        );

        $items = [];
        $total = 0.0;
        if ($itemsRs !== false) {
            foreach ($itemsRs->GetRows() as $row) {
                $qty       = (float) ($row['qty'] ?? 0);
                $price     = (float) ($row['price'] ?? 0);
                $lineTotal = round($qty * $price, 2);
                $total    += $lineTotal;
                $items[]   = [
                    'id'               => (string) $row['orderitemid'],
                    'orderId'          => (string) $row['orderid'],
                    'name'             => (string) $row['name'],
                    'qty'              => $qty,
                    'price'            => $price,
                    'total'            => $lineTotal,
                    'settled'          => !empty($row['settledpaymentid']),
                    'settledPaymentId' => $row['settledpaymentid'] ?? null,
                ];
            }
        }
        $total = round($total, 2);

        $paidRow = ncmExecute(
            'SELECT COALESCE(SUM(amount), 0) AS paid FROM space_session_payment WHERE companyid = ? AND sessionid = ?',
            [$companyId, $sessionId]
        );
        $paid = round((float) ($paidRow['paid'] ?? 0), 2);

        return [
            'total'   => $total,
            'paid'    => $paid,
            'balance' => round($total - $paid, 2),
            'items'   => $items,
        ];
    }

    /** true si el saldo está cubierto (0 o pagado de más, dentro de la tolerancia). */
    public static function isCovered(float $balance): bool
    {
        return $balance <= self::MONEY_EPSILON;
    }
}
