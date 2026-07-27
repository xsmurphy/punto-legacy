<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

use Punto\Api\Orders\OrderCoreService;

/**
 * SpaceSettlementService — split de cuenta (F3a+F3b+F3c,
 * context/15-espacios-module-plan.md §F3 "Plan técnico cerrado 2026-07-19").
 *
 * Cobrar una mesa hoy es atómico: `loadFromSession` → carrito → UNA
 * `transaction` → `markPaid` de TODAS las órdenes → `close` de la sesión.
 * Con split hay N cobros parciales contra la MISMA sesión, y ahí aparecen
 * los dos errores que cuestan plata: doble cobro (dos mozos cobrando lo
 * mismo, o dos requests concurrentes sobre el mismo saldo) y cobro
 * incompleto (la sesión se cierra con saldo pendiente).
 *
 * Modelo: `space_session_payment` es el ledger de pagos parciales (mig 90).
 * `pos_order_item.settledpaymentid` marca qué ítems ya se cobraron (solo
 * kind='items'). Saldo = total de la sesión − Σ pagos.
 *
 * Fiscal: cada pago parcial es SU PROPIA `transaction` (su propio
 * comprobante) — el `transactionId` viaja ya creado en `data` (mismo
 * contrato que OrderCoreService::markPaid). Este service NO crea
 * transacciones ni factura — solo lleva el ledger y decide cuándo el
 * saldo llegó a 0 para cerrar.
 *
 * Concurrencia: registerPayment() bloquea la fila de `space_session`
 * (`FOR UPDATE`) ANTES de leer el saldo — serializa TODOS los pagos
 * concurrentes sobre la misma sesión (mismo patrón que
 * OrderCoreService::updateStatus() bloqueando la orden). Para kind='items'
 * hay una segunda capa independiente: el CAS sobre `settledpaymentid`, que
 * es correcto por sí solo aunque el lock de sesión no existiera — un
 * UPDATE condicional de una sola sentencia no se puede ganar por carrera.
 */
final class SpaceSettlementService
{
    private const MONEY_EPSILON = 0.01;

    /** @var mixed */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Saldo actual de la sesión + detalle de ítems (para la UI de selección
     * "por ítems"). Dos queries de datos (ítems, pagos) — sin N+1.
     */
    public function getBalance(string $companyId, string $sessionId, ?string $outletScope = null): array
    {
        $this->findSession($companyId, $sessionId, $outletScope, false);
        $balance = $this->computeBalance($companyId, $sessionId);
        return array_merge(['sessionId' => $sessionId], $balance);
    }

    /**
     * Registra un cobro parcial y, si con este pago el saldo llega a 0,
     * liquida la sesión (markPaid de las órdenes activas + close) — TODO
     * dentro de UNA transacción.
     *
     * @param array{transactionId:string, kind:'items'|'amount'|'share',
     *              amount?:float, orderItemIds?:list<string>,
     *              shareCount?:int, shareIndex?:int} $data
     */
    public function registerPayment(string $companyId, string $sessionId, array $data, ?string $outletScope = null): array
    {
        global $db;

        $transactionId = trim((string) ($data['transactionId'] ?? ''));
        $kind          = (string) ($data['kind'] ?? '');
        if ($transactionId === '') {
            throw new \InvalidArgumentException('transactionId requerido');
        }
        if (!in_array($kind, ['items', 'amount', 'share'], true)) {
            throw new \InvalidArgumentException("kind inválido (esperado 'items'|'amount'|'share')");
        }

        $settled  = false;
        $outletId = null;

        $db->StartTrans();
        try {
            // Lock de la sesión ANTES de leer el saldo: sin esto, dos requests
            // concurrentes (dos mozos cobrando la misma mesa) podrían leer el
            // mismo saldo pendiente y ambos validar contra él → sobre-cobro.
            // El segundo registerPayment queda bloqueado hasta que el commit
            // (o rollback) de este termine, y entonces relee el saldo YA
            // actualizado.
            $sessionRow = $this->findSession($companyId, $sessionId, $outletScope, true);
            if (!in_array((string) $sessionRow['status'], ['open', 'bill_requested'], true)) {
                throw new \InvalidArgumentException('La sesión no admite pagos (status: ' . $sessionRow['status'] . ')');
            }
            $outletId = (string) $sessionRow['outletid'];

            // Idempotencia por transactionId. Un reintento del cliente (timeout
            // que en realidad llegó, doble tap) volvería a insertar un renglón
            // del ledger y el pago se contaría DOS VECES. `kind='items'` estaba
            // cubierto por el CAS de settledpaymentid, pero 'amount' y 'share'
            // no tenían nada — hallazgo del code-reviewer, y es plata.
            // Se resuelve como no-op idempotente (no error): el cliente que
            // reintenta obtiene el mismo estado que si hubiera funcionado la
            // primera vez, igual que la dedupe por `uid` de las ventas. El
            // índice único de la mig 91 es el respaldo estructural.
            $dup = ncmExecute(
                'SELECT paymentid FROM space_session_payment
                  WHERE companyid = ? AND transactionid = ? LIMIT 1',
                [$companyId, $transactionId]
            );
            if ($dup) {
                $db->CompleteTrans();
                return $this->getBalance($companyId, $sessionId, $outletScope);
            }

            $balanceInfo = $this->computeBalance($companyId, $sessionId);
            $balance     = $balanceInfo['balance'];
            $decimals    = $this->currencyDecimals($companyId);
            $paymentId   = generateUuidV7();
            $shareCountForRow = null;

            if ($kind === 'items') {
                $orderItemIds = array_values(array_unique(array_filter(
                    array_map('strval', (array) ($data['orderItemIds'] ?? [])),
                    static fn (string $v): bool => $v !== ''
                )));
                if ($orderItemIds === []) {
                    throw new \InvalidArgumentException('orderItemIds requerido para kind=items');
                }

                // Monto SIEMPRE calculado server-side a partir del precio/qty
                // persistidos — nunca del amount que pudiera venir en el
                // request. Este SELECT también valida que los ítems
                // pertenezcan a ESTA sesión, no estén cancelados y su orden
                // siga activa; es informativo para el amount, la garantía
                // real contra el doble cobro es el CAS de abajo.
                $placeholders = implode(',', array_fill(0, count($orderItemIds), '?'));
                $candidatesRs = $this->db->Execute(
                    "SELECT oi.orderitemid, oi.qty, oi.price
                       FROM pos_order_item oi
                       JOIN pos_order o ON o.orderid = oi.orderid AND o.companyid = oi.companyid
                      WHERE oi.orderitemid IN ($placeholders)
                        AND oi.companyid = ?
                        AND o.spacesessionid = ?
                        AND o.status NOT IN ('closed','cancelled')
                        AND oi.status != 'cancelled'",
                    array_merge($orderItemIds, [$companyId, $sessionId])
                );
                $candidates = $candidatesRs !== false ? $candidatesRs->GetRows() : [];
                if (count($candidates) !== count($orderItemIds)) {
                    throw new \InvalidArgumentException('Alguno de los ítems no pertenece a esta sesión, está cancelado, o su orden ya no está activa');
                }
                $amount = 0.0;
                foreach ($candidates as $row) {
                    $amount += ((float) $row['qty']) * ((float) $row['price']);
                }
                $amount = round($amount, 2);

                // CAS anti doble cobro: marcar un ítem ya marcado no afecta
                // filas. Si el conteo de filas afectadas no coincide EXACTO
                // con lo pedido, se aborta TODA la transacción — ningún ítem
                // queda a medio marcar y el ledger no se inserta. No es una
                // validación previa que se pueda ganar por carrera: es la
                // única sentencia (UPDATE condicional) que decide.
                $casRs = $this->db->Execute(
                    "UPDATE pos_order_item
                        SET settledpaymentid = ?
                      WHERE orderitemid IN ($placeholders)
                        AND companyid = ?
                        AND settledpaymentid IS NULL
                      RETURNING orderitemid",
                    array_merge([$paymentId], $orderItemIds, [$companyId])
                );
                $affected = $casRs !== false ? count($casRs->GetRows()) : 0;
                if ($affected !== count($orderItemIds)) {
                    throw new \RuntimeException('Alguno de los ítems ya fue cobrado');
                }
            } elseif ($kind === 'share') {
                $shareCount = (int) ($data['shareCount'] ?? 0);
                $shareIndex = (int) ($data['shareIndex'] ?? 0);
                if ($shareCount < 2) {
                    throw new \InvalidArgumentException('shareCount debe ser >= 2');
                }
                if ($shareIndex < 1 || $shareIndex > $shareCount) {
                    throw new \InvalidArgumentException('shareIndex fuera de rango (1..shareCount)');
                }
                // Las partes se calculan sobre el TOTAL de la sesión (no el
                // saldo pendiente en el momento de ESTE pago puntual) — así
                // el monto de "parte 2 de 3" es el mismo sin importar en qué
                // orden se cobren las partes o si esta es la primera o la
                // última en llegar. La última parte absorbe el resto del
                // redondeo (ver splitShares). Si se mezclara con otro pago
                // previo (kind='items'/'amount') sobre la misma sesión, la
                // validación de saldo de abajo rechaza la parte que ya no
                // entra — no se permite silenciosamente un split inconsistente.
                $parts  = $this->splitShares($balanceInfo['total'], $shareCount, $decimals);
                $amount = $parts[$shareIndex - 1];
                $shareCountForRow = $shareCount;
            } else { // amount
                // Redondeo a los decimales de la moneda del tenant, igual que
                // la rama `share`. Con `round(..., 2)` fijo, en guaraníes (0
                // decimales) entraba un monto con centavos que la caja no
                // puede cobrar.
                $amount = round((float) ($data['amount'] ?? 0), $decimals);
            }

            if ($amount <= 0) {
                throw new \InvalidArgumentException('El monto del pago debe ser mayor a 0');
            }
            // Tolerancia de un centavo: la última parte de un split absorbe
            // el resto del redondeo (ver splitShares) y puede diferir en
            // 1 unidad mínima de moneda del balance recalculado acá.
            if (round($amount - $balance, 2) > self::MONEY_EPSILON) {
                throw new \InvalidArgumentException('El monto excede el saldo pendiente de la sesión');
            }

            $ok = $this->db->Execute(
                'INSERT INTO space_session_payment
                    (paymentid, companyid, outletid, sessionid, transactionid, amount, kind, sharecount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$paymentId, $companyId, $outletId, $sessionId, $transactionId, $amount, $kind, $shareCountForRow]
            );
            if ($ok === false) {
                throw new \RuntimeException('No se pudo registrar el pago');
            }

            // settleIfCovered corre DENTRO de esta misma TX (StartTrans anida
            // con depth counter, ver api/includes/lib/DB.php) — el cierre de
            // la sesión y el markPaid de las órdenes son atómicos con el pago
            // que los dispara. Es la única forma correcta de garantizar que
            // "ningún parcial cierra antes de saldo 0": si el INSERT de
            // arriba conmutara en su propia TX y ESTA fallara después, el
            // pago quedaría commiteado sin el cierre correspondiente.
            $settled = $this->settleIfCovered($companyId, $sessionId, $transactionId);
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al registrar el pago (transacción abortada)');
        }

        // Publishes post-commit — recién ahora que la TX conmutó de verdad.
        if ($outletId !== null) {
            $this->publishBalance($companyId, $sessionId, $outletId);
        }
        if ($settled) {
            $orders = new OrderCoreService($this->db);
            $activeRs = ncmExecute(
                "SELECT orderid FROM pos_order WHERE spacesessionid = ? AND companyid = ? AND status = 'closed' AND saletransactionid = ?",
                [$sessionId, $companyId, $transactionId],
                false,
                true
            );
            if ($activeRs && is_object($activeRs)) {
                while (!$activeRs->EOF) {
                    $orders->publishOrderStatus($companyId, (string) $activeRs->fields['orderid']);
                    $activeRs->MoveNext();
                }
                $activeRs->Close();
            }
            (new SpaceSessionService($this->db))->publishSessionState($companyId, $sessionId);
        }

        return $this->getBalance($companyId, $sessionId, $outletScope);
    }

    /**
     * Si el saldo de la sesión es ≤ 0, hace markPaid() de todas sus órdenes
     * activas y close() de la sesión. Si el saldo sigue pendiente, no hace
     * NADA (ni siquiera toca la sesión) — el cierre SOLO ocurre en el pago
     * que lleva el saldo a 0, nunca en un parcial. Idempotente: si la
     * sesión ya está closed/cancelled, es un no-op.
     *
     * Reabre su propia TX (nesteable — ver StartTrans/depth counter) para
     * poder llamarse de forma independiente; cuando la llama
     * registerPayment() ya dentro de su propia TX, esto es la misma
     * transacción física, solo un nivel de anidamiento más.
     *
     * NO publica por WS a propósito (mismo criterio que
     * SpaceSessionService::reopenFromBillRequested): markPaid()/close() ya
     * difieren su publish propio mientras InTrans() sea true, así que
     * publicar acá, todavía dentro de la TX, sería prematuro si este método
     * corre anidado. El ÚNICO caller hoy es registerPayment(), que publica
     * post-commit tras SU CompleteTrans real. Un futuro caller standalone
     * de settleIfCovered() (fuera de registerPayment) es responsable de
     * publicar el estado (órdenes + sesión) después de que ESTE método
     * retorne.
     */
    public function settleIfCovered(string $companyId, string $sessionId, string $transactionId): bool
    {
        global $db;
        $settled = false;

        $db->StartTrans();
        try {
            // Re-lockear la fila es un no-op para Postgres si ya la tenemos
            // (misma TX, mismo lock) — deja este método seguro de invocar
            // standalone, sin depender de que el caller ya haya bloqueado.
            $session = $this->db->Execute(
                'SELECT sessionid, outletid, status FROM space_session WHERE sessionid = ? AND companyid = ? FOR UPDATE',
                [$sessionId, $companyId]
            );
            if ($session === false || $session->EOF) {
                throw new \RuntimeException('Sesión no encontrada');
            }
            $row = [];
            foreach ($session->fields as $k => $v) $row[$k] = $v;

            if (!in_array((string) $row['status'], ['closed', 'cancelled'], true)) {
                $balance = $this->computeBalance($companyId, $sessionId)['balance'];
                if ($balance <= self::MONEY_EPSILON) {
                    $orders = new OrderCoreService($this->db);
                    $activeRs = $this->db->Execute(
                        "SELECT orderid FROM pos_order WHERE spacesessionid = ? AND companyid = ? AND status NOT IN ('closed','cancelled')",
                        [$sessionId, $companyId]
                    );
                    $orderIds = $activeRs !== false
                        ? array_map(static fn ($r) => (string) $r['orderid'], $activeRs->GetRows())
                        : [];
                    foreach ($orderIds as $orderId) {
                        // markPaid() difiere su publish cuando InTrans() (ver
                        // OrderCoreService) — acá SIEMPRE estamos dentro de
                        // una TX (la propia o la anidada de registerPayment),
                        // así que nunca publica antes de tiempo. El caller
                        // (registerPayment) republica post-commit iterando
                        // las órdenes recién cerradas.
                        $orders->markPaid($companyId, $orderId, $transactionId);
                    }
                    (new SpaceSessionService($this->db))->close($companyId, $sessionId, $transactionId);
                    $settled = true;
                }
            }
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }
        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('Fallo al liquidar la sesión (transacción abortada)');
        }
        return $settled;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * total = Σ (qty × price) de ítems NO cancelados de órdenes NO
     * cerradas/canceladas de la sesión (mismo criterio que
     * `loadFromSession` en frontend/lib/cart/store.ts — el shape de "qué
     * cuenta para el total de la mesa" es único, no diverge entre front y
     * back). paid = Σ ledger. balance = total − paid.
     *
     * Dos queries (ítems, pagos) — el total sale de agregar la query de
     * ítems en PHP, no de una tercera query.
     */
    private function computeBalance(string $companyId, string $sessionId): array
    {
        $itemsRs = $this->db->Execute(
            "SELECT oi.orderitemid, oi.orderid, oi.name, oi.qty, oi.price, oi.settledpaymentid
               FROM pos_order_item oi
               JOIN pos_order o ON o.orderid = oi.orderid AND o.companyid = oi.companyid
              WHERE o.spacesessionid = ? AND o.companyid = ?
                AND o.status NOT IN ('closed','cancelled')
                AND oi.status != 'cancelled'
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

    /**
     * Reparte $total en $shareCount partes exactas en la precisión de
     * moneda del tenant ($decimals: 0 para PYG, 2 para USD/etc) — la
     * ÚLTIMA parte absorbe el resto del redondeo. Ej. 100.000/3 en PYG
     * (0 decimales) → 33.333 + 33.333 + 33.334 = 100.000 exacto.
     *
     * Aritmética en enteros (unidad mínima de moneda escalada por
     * 10^decimals) para no arrastrar error de punto flotante — dos floats
     * NUNCA se sumarían exacto a $total en casos como 1/3.
     *
     * @return list<float> shareCount montos, en orden (índice 0 = parte 1).
     */
    private function splitShares(float $total, int $shareCount, int $decimals): array
    {
        $scale       = (int) (10 ** $decimals);
        $totalScaled = (int) round($total * $scale);
        $unitScaled  = intdiv($totalScaled, $shareCount);
        $remainder   = $totalScaled - ($unitScaled * $shareCount);

        $parts = array_fill(0, $shareCount, $unitScaled);
        $parts[$shareCount - 1] += $remainder;

        return array_map(static fn (int $p): float => round($p / $scale, $decimals), $parts);
    }

    /**
     * settingDecimal es 'yes'/'no' (usar decimales o no), NO un conteo de
     * dígitos — mismo campo que expone /v1/bootstrap (api/v1/bootstrap.php).
     * 'no' (ej. PYG) → 0 decimales; 'yes' → 2.
     */
    private function currencyDecimals(string $companyId): int
    {
        $row = ncmExecute(
            "SELECT config->>'settingDecimal' AS decimalflag FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        $flag = $row ? (string) ($row['decimalflag'] ?? '') : '';
        return $flag === 'yes' ? 2 : 0;
    }

    /**
     * @return array{sessionid:string,companyid:string,outletid:string,status:string}
     */
    private function findSession(string $companyId, string $sessionId, ?string $outletScope, bool $forUpdate): array
    {
        $sql = 'SELECT sessionid, companyid, outletid, status FROM space_session WHERE sessionid = ? AND companyid = ?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $rs = $this->db->Execute($sql, [$sessionId, $companyId]);
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('Sesión no encontrada');
        }
        $row = [];
        foreach ($rs->fields as $k => $v) $row[$k] = $v;
        if ($outletScope !== null && (string) $row['outletid'] !== $outletScope) {
            // No revelar existencia cross-outlet (mismo patrón SpaceSessionService).
            throw new \RuntimeException('Sesión no encontrada');
        }
        return $row;
    }

    private function publishBalance(string $companyId, string $sessionId, string $outletId): void
    {
        $balance = $this->getBalance($companyId, $sessionId);
        wsPublish($companyId . ':spaces:' . $outletId, 'space:settlement', [
            'outletId' => $outletId,
            'balance'  => $balance,
        ]);
        realtimePublish('space', 'update');
    }
}
