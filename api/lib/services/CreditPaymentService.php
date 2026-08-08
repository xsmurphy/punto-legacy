<?php
declare(strict_types=1);
namespace Punto\Api\Services;

/**
 * CreditPaymentService — registra cobros parciales/totales de crédito (type=5).
 *
 * Replica la lógica de VPaymentService::settleCreditInvoice pero iniciado
 * manualmente por el operador desde el POS o el panel.
 *
 * Invariantes de seguridad:
 *   - Todas las queries scopeadas por companyId (multi-tenant).
 *   - La fila padre se bloquea con SELECT … FOR UPDATE DENTRO de la TX
 *     para evitar doble-cobro concurrente.
 *   - paymentMethodName se resuelve server-side; el caller solo envía la key.
 */
final class CreditPaymentService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private TransactionLinkService $links;

    public function __construct()
    {
        $this->links = new TransactionLinkService();
    }

    private function generateUID(): string
    {
        return (string) (number_format(microtime(true) * 1000, 0, '.', ''));
    }

    /**
     * Registra UN recibo de pago de crédito, repartido en N facturas del
     * MISMO cliente (mig 123 — antes esto requería un recibo por factura,
     * quemando N números correlativos para un solo documento fiscal real).
     *
     * @param string $companyId        Tenant (siempre del JWT, nunca del body).
     * @param string $userId           Operador que cobra (del JWT).
     * @param array  $allocations      list<{parentTransactionId: string, amount: float}> —
     *                                 al menos 1; duplicados por parentTransactionId se
     *                                 mergean (suman) antes de validar.
     * @param string $paymentMethodKey Key del método de pago (ej. "efectivo").
     * @return array {id, encId, amount, parentComplete, paid, debtRemaining, allocations}
     *               `amount`/`parentComplete`/`paid`/`debtRemaining` son el shape legacy
     *               (compat con el caller de una sola factura — ver `allocations` para el
     *               detalle real por factura cuando hay más de una).
     */
    public function create(
        string  $companyId,
        string  $userId,
        array   $allocations,
        string  $paymentMethodKey,
        ?string $note = null,
        ?string $identifier = null
    ): array {
        global $db;

        // ── 1. Validación de forma — mergear duplicados por parentTransactionId
        //    (sumando montos) ANTES de validar, así un caller que mande la
        //    misma factura dos veces no es un error sino un solo allocation
        //    con el total sumado. ──────────────────────────────────────────
        $merged = [];
        foreach ($allocations as $alloc) {
            $pid = (string) ($alloc['parentTransactionId'] ?? '');
            $amt = (float) ($alloc['amount'] ?? 0);
            if (!preg_match(self::UUID_RE, $pid)) {
                apiError('parentTransactionId inválido en allocations', 422);
            }
            if ($amt <= 0) {
                apiError('Cada allocation necesita un amount > 0', 422);
            }
            $merged[$pid] = ($merged[$pid] ?? 0.0) + $amt;
        }
        if ($merged === []) {
            apiError('Se requiere al menos una allocation', 422);
        }
        // Orden de llegada (post-merge, primera aparición) — determina de qué
        // factura se toman registerId/outletId/responsibleId del recibo (ver
        // más abajo) y el orden del array `allocations` de salida.
        $orderedParentIds = array_keys($merged);

        // Resolver nombre del método de pago server-side (nunca confiar en el body).
        $paymentMethodName = getPaymentMethodName($paymentMethodKey);

        $db->StartTrans();

        // ── 2. Lock de TODAS las facturas padre en una sola query, ORDER BY
        //    transactionId ASC + FOR UPDATE: Postgres bloquea las filas en el
        //    orden en que las entrega el Sort subyacente a LockRows, así que
        //    ordenar por un criterio determinístico (el PK) evita deadlock
        //    entre dos requests concurrentes que cobren el mismo PAR de
        //    facturas en orden distinto (A cobra fact.1→fact.2 mientras B
        //    cobra fact.2→fact.1 al mismo tiempo). ncmExecute con
        //    getAssoc=true (NO forceObj — acá necesitamos el array completo
        //    ya materializado antes de seguir armando el resto de la TX).
        $ph = implode(',', array_fill(0, count($orderedParentIds), '?'));
        $rows = ncmExecute(
            "SELECT * FROM transaction WHERE transactionId IN ($ph) AND companyId = ?
             ORDER BY transactionId ASC FOR UPDATE",
            array_merge($orderedParentIds, [$companyId]),
            false, false, true
        );
        $rows = is_array($rows) ? $rows : [];
        $parents = [];
        foreach ($rows as $r) {
            $parents[(string) $r['transactionId']] = $r;
        }

        foreach ($orderedParentIds as $pid) {
            if (!isset($parents[$pid])) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('Transacción no encontrada: ' . $pid, 404);
            }
        }

        // ── 3. Validaciones de negocio: mismo companyId (ya lo garantiza el
        //    WHERE), type=3, no completas, y MISMO customerId — un recibo es
        //    de un solo cliente. ───────────────────────────────────────────
        $customerId = (string) ($parents[$orderedParentIds[0]]['customerId'] ?? '');
        foreach ($orderedParentIds as $pid) {
            $p = $parents[$pid];
            if ((string) ($p['transactionType'] ?? '') !== '3') {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('Solo se puede pagar facturas a crédito (transactionId=' . $pid . ')', 422);
            }
            if ((int) ($p['transactionComplete'] ?? 0) === 1) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('Factura ya saldada: ' . $pid, 422);
            }
            if ((string) ($p['customerId'] ?? '') !== $customerId) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('Todas las facturas de un recibo deben ser del mismo cliente', 422);
            }
        }

        // ── 4. Deuda de cada factura DENTRO de la TX (después del lock, con
        //    sumDerivedAmounts — mig 123, respeta `amount` de vínculos
        //    previos si esa factura ya recibió pagos parciales repartidos). ──
        $debts = [];
        foreach ($orderedParentIds as $pid) {
            $p     = $parents[$pid];
            $total = (float) ($p['transactionTotal'] ?? 0) - (float) ($p['transactionDiscount'] ?? 0);
            $paid  = $this->links->sumDerivedAmounts($companyId, $pid, 'credit_payment');
            $debt  = max(0.0, $total - $paid);
            $amt   = $merged[$pid];
            if (round($amt, 4) > round($debt, 4) + 0.001) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('El monto imputado a la factura ' . $pid . ' supera su deuda actual', 422);
            }
            $debts[$pid] = ['total' => $total, 'paid' => $paid, 'debt' => $debt];
        }

        $totalAmount = array_sum($merged);

        // registerId/outletId/responsibleId del recibo: se toman de la
        // PRIMERA factura de la lista (orden de llegada). Elección deliberada
        // — con varias facturas no hay un único register "correcto"; se picha
        // el de la que el operador puso primero en el diálogo.
        $firstParent      = $parents[$orderedParentIds[0]];
        $parentRegisterId = (string) ($firstParent['registerId'] ?? '');

        // Sesión de caja: drawerId de la caja ABIERTA del register de la
        // primera factura. null si no hay caja abierta → el pago se registra
        // igual (recuperable por el fallback de fecha del resumen, mig 70).
        $openDrawerId = DrawerService::resolveOpenDrawerId($parentRegisterId, $companyId);

        $tPay = [
            'transactionDate'        => TODAY,
            'transactionTotal'       => $totalAmount,
            'transactionType'        => 5,
            'transactionComplete'    => 1,
            'transactionStatus'      => 1,
            'transactionPaymentType' => json_encode([array_merge(
                [
                    'type'  => $paymentMethodKey,
                    'name'  => $paymentMethodName,
                    'total' => $totalAmount,
                ],
                ($identifier !== null && $identifier !== '') ? ['identifier' => $identifier] : [],
            )]),
            'transactionUID'         => $this->generateUID(),
            // UN solo invoiceNo para todo el recibo, sin importar cuántas
            // facturas cancela — ese es el punto de este mig (antes: N
            // recibos = N invoiceNo quemados por un solo documento real).
            'invoiceNo'              => getNextDocNumber(0, 5, $companyId, $parentRegisterId),
            'timestamp'              => time(),
            'customerId'             => $customerId,
            'registerId'             => $parentRegisterId,
            'userId'                 => $userId,
            'responsibleId'          => $firstParent['responsibleId'],
            'outletId'               => $firstParent['outletId'],
            'companyId'              => $companyId,
            'drawerId'               => $openDrawerId,
        ];
        if ($note !== null && $note !== '') {
            $tPay['transactionNote'] = $note;
        }

        $ok = $db->AutoExecute('transaction', $tPay, 'INSERT');
        if (!$ok) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('Error al registrar pago', 500);
        }

        $newId = (string) $db->Insert_ID();

        // ── 5. Un link `credit_payment` POR FACTURA, cada uno con SU monto
        //    (mig 123 — antes era 1 link sin amount, ahora N links del mismo
        //    recibo con `amount` = lo imputado a esa factura puntual). ──────
        $resultAllocations = [];
        foreach ($orderedParentIds as $pid) {
            $amt = $merged[$pid];
            $this->links->link($companyId, $pid, $newId, 'credit_payment', $amt);

            $debtRemaining  = max(0.0, $debts[$pid]['debt'] - $amt);
            $parentComplete = round($debtRemaining, 4) <= 0;
            if ($parentComplete) {
                $db->Execute(
                    'UPDATE transaction SET transactionComplete = TRUE WHERE transactionId = ? AND companyId = ?',
                    [$pid, $companyId]
                );
            }
            $resultAllocations[] = [
                'parentTransactionId' => $pid,
                'amount'              => $amt,
                'parentComplete'      => $parentComplete,
                'debtRemaining'       => $debtRemaining,
            ];
        }

        $db->CompleteTrans();

        // Notificación realtime best-effort (post-commit).
        try {
            realtimePublish('transaction', 'update', null);
        } catch (\Throwable $e) {
            // Ignorar — no crítico.
        }

        // Shape legacy top-level (compat con el caller de una sola factura —
        // POS): con un solo allocation, `parentComplete`/`paid`/`debtRemaining`
        // son exactamente los de esa factura. Con varias, son un agregado
        // best-effort (AND de completitud, SUMA de deuda restante) — el
        // detalle real por factura vive en `allocations`.
        $first = $resultAllocations[0];
        return [
            'id'             => $newId,
            // enc($newId) — la transacción type=5 (recibo de pago) recién creada,
            // en el formato que espera el BFF `/pos/transactions/[id]` (dec()
            // server-side). El front la usa para pedir el detalle e imprimir el
            // recibo (bug: pagar crédito no ofrecía imprimir el comprobante).
            'encId'          => enc($newId),
            'amount'         => $totalAmount,
            'parentComplete' => array_reduce($resultAllocations, static fn ($carry, $a) => $carry && $a['parentComplete'], true),
            'paid'           => $debts[$orderedParentIds[0]]['paid'] + $first['amount'],
            'debtRemaining'  => array_sum(array_column($resultAllocations, 'debtRemaining')),
            'allocations'    => $resultAllocations,
        ];
    }
}
