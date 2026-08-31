<?php
declare(strict_types=1);

namespace Punto\Api\Spaces;

use Punto\Api\Orders\OrderCoreService;
use Punto\Api\Services\TransactionLinkService;

/**
 * SpaceSettlementService — split de cuenta (F3a+F3b+F3c,
 * context/15-espacios-module-plan.md §F3 "Plan técnico cerrado 2026-07-19").
 *
 * Cobrar un espacio hoy es atómico: `loadFromSession` → carrito → UNA
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
    private const MONEY_EPSILON = SpaceBalanceService::MONEY_EPSILON;

    /** @var mixed */
    private $db;

    private SpaceBalanceService $balances;

    public function __construct($db)
    {
        $this->db       = $db;
        $this->balances = new SpaceBalanceService($db);
    }

    /**
     * Saldo actual de la sesión + detalle de ítems (para la UI de selección
     * "por ítems"). Dos queries de datos (ítems, pagos) — sin N+1.
     */
    public function getBalance(string $companyId, string $sessionId, ?string $outletScope = null): array
    {
        $this->findSession($companyId, $sessionId, $outletScope, false);
        $balance = $this->computeBalance($companyId, $sessionId);
        return array_merge(
            ['sessionId' => $sessionId, 'lockedFamily' => $this->lockedFamily($companyId, $sessionId)],
            $balance
        );
    }

    /**
     * Familia de modo ya comprometida en esta sesión, o null si todavía no se
     * cobró nada (todos los modos disponibles). No se pueden mezclar: ver el
     * comentario en registerPayment(). La UI lo usa para deshabilitar los
     * modos que ya no aplican — pero el enforcement real está en
     * registerPayment(), no acá.
     *
     * @return 'items'|'amount'|null
     */
    private function lockedFamily(string $companyId, string $sessionId): ?string
    {
        $row = ncmExecute(
            "SELECT
                 COUNT(*) FILTER (WHERE kind = 'items')  AS items_count,
                 COUNT(*) FILTER (WHERE kind <> 'items') AS amount_count
               FROM space_session_payment
              WHERE sessionid = ? AND companyid = ?",
            [$sessionId, $companyId]
        );
        if ((int) ($row['items_count'] ?? 0) > 0)  return 'items';
        if ((int) ($row['amount_count'] ?? 0) > 0) return 'amount';
        return null;
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
            // concurrentes (dos mozos cobrando el mismo espacio) podrían leer el
            // mismo saldo pendiente y ambos validar contra él → sobre-cobro.
            // El segundo registerPayment queda bloqueado hasta que el commit
            // (o rollback) de este termine, y entonces relee el saldo YA
            // actualizado. Este lock es EXCLUSIVO del camino de escritura —
            // preflightPayment() (más abajo) es de solo lectura y nunca lo toma.
            $sessionRow = $this->findSession($companyId, $sessionId, $outletScope, true);
            $outletId   = (string) $sessionRow['outletid'];

            // Idempotencia por transactionId. Un reintento del cliente (timeout
            // que en realidad llegó, doble tap) volvería a insertar un renglón
            // del ledger y el pago se contaría DOS VECES. `kind='items'` estaba
            // cubierto por el CAS de settledpaymentid, pero 'amount' y 'share'
            // no tenían nada — hallazgo del code-reviewer, y es plata.
            // Se resuelve como no-op idempotente (no error): el cliente que
            // reintenta obtiene el mismo estado que si hubiera funcionado la
            // primera vez, igual que la dedupe por `uid` de las ventas. El
            // índice único de la mig 91 es el respaldo estructural. Esto NO
            // se puede mover al preflight: depende de la escritura real (ver
            // docblock de preflightPayment).
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

            // Validaciones + cálculo de amount: MISMA función que usa el
            // preflight de solo lectura (preflightPayment) — una sola
            // definición de las reglas, ver docblock de validateAndComputeAmount.
            $amount = $this->validateAndComputeAmount($companyId, $sessionId, $sessionRow, $data, $kind, $balanceInfo);

            $decimals  = $this->currencyDecimals($companyId);
            $paymentId = generateUuidV7();
            $shareCountForRow = $kind === 'share' ? (int) ($data['shareCount'] ?? 0) : null;

            if ($kind === 'items') {
                $orderItemIds = array_values(array_unique(array_filter(
                    array_map('strval', (array) ($data['orderItemIds'] ?? [])),
                    static fn (string $v): bool => $v !== ''
                )));
                $placeholders = implode(',', array_fill(0, count($orderItemIds), '?'));

                // CAS anti doble cobro: marcar un ítem ya marcado no afecta
                // filas. Si el conteo de filas afectadas no coincide EXACTO
                // con lo pedido, se aborta TODA la transacción — ningún ítem
                // queda a medio marcar y el ledger no se inserta. No es una
                // validación previa que se pueda ganar por carrera: es la
                // única sentencia (UPDATE condicional) que decide. Por eso
                // esto vive SOLO acá, no en el preflight (ver docblock de
                // preflightPayment).
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
            // Órdenes de este espacio que quedaron cobradas por $transactionId
            // — antes un WHERE directo sobre pos_order.saletransactionid
            // (columna dropeada, mig 115), ahora: IDs de órdenes vinculadas a
            // la transacción (TransactionLinkService) filtrados por sesión +
            // status en pos_order (tabla no relacionada, SQL directo OK).
            $linkedOrderIds = (new TransactionLinkService())->listOrderIdsForTransaction($companyId, $transactionId);
            if ($linkedOrderIds !== []) {
                $placeholders = implode(',', array_fill(0, count($linkedOrderIds), '?'));
                $activeRs = ncmExecute(
                    "SELECT orderid FROM pos_order
                      WHERE spacesessionid = ? AND companyid = ? AND status = 'closed' AND orderid IN ($placeholders)",
                    array_merge([$sessionId, $companyId], $linkedOrderIds),
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
            }
            (new SpaceSessionService($this->db))->publishSessionState($companyId, $sessionId);
        }

        return $this->getBalance($companyId, $sessionId, $outletScope);
    }

    /**
     * Preflight de solo lectura: corre EXACTAMENTE las mismas validaciones
     * que registerPayment() (vía validateAndComputeAmount, la única
     * definición de las reglas) y devuelve el monto que se registraría, sin
     * escribir nada. Pensado para el caller (POS) que hoy crea la venta
     * ANTES de intentar el pago parcial — el orden correcto es preguntar acá
     * primero y recién crear la venta si esto no lanza.
     *
     * Qué NO valida (y por qué no puede):
     *   - Idempotencia por transactionId: irrelevante en preflight porque el
     *     transactionId de una venta que todavía no se creó no existe en el
     *     ledger — no hay nada que deduplicar.
     *   - El CAS de settledpaymentid (kind='items'): es un UPDATE condicional
     *     de una sola sentencia, la única garantía real contra el doble
     *     cobro. No se puede "simular" en seco sin escribir — cualquier
     *     SELECT previo es solo informativo y puede quedar obsoleto en el
     *     instante entre el preflight y el registerPayment real.
     *
     * Por eso este método REDUCE la ventana de la carrera (el caso común de
     * "el espacio ya se cobra por ítems" se detecta ANTES de comprometer la
     * venta) pero NO la elimina — registerPayment() sigue siendo la
     * autoridad final y puede rechazar algo que el preflight aprobó
     * (ej. otro dispositivo cobró en el medio). El caller SIEMPRE debe
     * manejar el error de registerPayment() aunque haya pasado el preflight.
     *
     * Sin FOR UPDATE a propósito: es lectura, no debe bloquear la fila de la
     * sesión — ese lock es exclusivo del camino de escritura.
     *
     * @param array{transactionId?:string, kind:'items'|'amount'|'share',
     *              amount?:float, orderItemIds?:list<string>,
     *              shareCount?:int, shareIndex?:int} $data
     * @return array{ok:true, amount:float, balance:float}
     */
    public function preflightPayment(string $companyId, string $sessionId, array $data, ?string $outletScope = null): array
    {
        $kind = (string) ($data['kind'] ?? '');
        if (!in_array($kind, ['items', 'amount', 'share'], true)) {
            throw new \InvalidArgumentException("kind inválido (esperado 'items'|'amount'|'share')");
        }

        $sessionRow  = $this->findSession($companyId, $sessionId, $outletScope, false);
        $balanceInfo = $this->computeBalance($companyId, $sessionId);
        $amount      = $this->validateAndComputeAmount($companyId, $sessionId, $sessionRow, $data, $kind, $balanceInfo);

        return ['ok' => true, 'amount' => $amount, 'balance' => $balanceInfo['balance']];
    }

    /**
     * Única definición de las reglas de un pago parcial — la comparten
     * registerPayment() (camino de escritura) y preflightPayment() (lectura
     * pura). Si mañana se agrega una validación, agregarla ACÁ alcanza: los
     * dos callers la heredan sin que nadie se acuerde de copiarla.
     *
     * Valida: status de la sesión, mezcla de familias items↔amount/share,
     * pertenencia/vigencia de los ítems (kind=items, SELECT informativo —
     * NO el CAS de escritura, eso es exclusivo de registerPayment),
     * shareCount/shareIndex (kind=share), amount>0, amount <= saldo.
     *
     * NO valida idempotencia por transactionId ni corre el CAS de
     * settledpaymentid — ver docblock de preflightPayment para el porqué.
     *
     * @return float el amount calculado/validado para este pago
     */
    private function validateAndComputeAmount(
        string $companyId,
        string $sessionId,
        array $sessionRow,
        array $data,
        string $kind,
        array $balanceInfo
    ): float {
        if (!in_array((string) $sessionRow['status'], ['open', 'bill_requested'], true)) {
            throw new \InvalidArgumentException('La sesión no admite pagos (status: ' . $sessionRow['status'] . ')');
        }

        // No se pueden MEZCLAR familias de modo en una misma sesión
        // (decisión del owner 2026-07-19). Motivo — es un problema de
        // STOCK, no de plata:
        //   - 'items' marca los ítems como saldados (CAS) y descuenta su
        //     stock una vez.
        //   - 'amount'/'share' prorratean sobre los ítems no saldados para
        //     poder facturar mercadería real (`/v1/sales` rechaza líneas
        //     sin itemId), pero NO los marcan.
        // Mezclando ambas, un ítem prorrateado por un monto libre puede
        // volver a cobrarse por 'items' y su stock se descuenta DOS veces.
        // La plata queda bien (el ledger la cuenta una sola vez); el
        // inventario deriva en silencio, que es peor porque no se nota.
        $family    = $kind === 'items' ? 'items' : 'amount';
        $otherKind = ncmExecute(
            $family === 'items'
                ? "SELECT kind FROM space_session_payment
                    WHERE sessionid = ? AND companyid = ? AND kind <> 'items' LIMIT 1"
                : "SELECT kind FROM space_session_payment
                    WHERE sessionid = ? AND companyid = ? AND kind = 'items' LIMIT 1",
            [$sessionId, $companyId]
        );
        if ($otherKind) {
            throw new \InvalidArgumentException(
                $family === 'items'
                    ? 'Este espacio ya se está cobrando por monto o por partes: no se puede pasar a cobro por ítems'
                    : 'Este espacio ya se está cobrando por ítems: no se puede pasar a monto libre ni a partes iguales'
            );
        }

        $balance  = $balanceInfo['balance'];
        $decimals = $this->currencyDecimals($companyId);

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
            // siga activa; es informativo para el amount y para el
            // preflight — la garantía real contra el doble cobro es el CAS
            // que corre en registerPayment(), no acá.
            $placeholders = implode(',', array_fill(0, count($orderItemIds), '?'));
            $candidatesRs = $this->db->Execute(
                "SELECT oi.orderitemid, oi.qty, oi.price
                   FROM pos_order_item oi
                   JOIN pos_order o ON o.orderid = oi.orderid AND o.companyid = oi.companyid
                  WHERE oi.orderitemid IN ($placeholders)
                    AND oi.companyid = ?
                    AND o.spacesessionid = ?
                    AND o.status NOT IN ('closed','cancelled')
                    AND oi.status != 'cancelled'
                    -- Una hija de add-on no es una unidad cobrable (context/41,
                    -- mig 139): no lleva plata propia y se paga con su padre.
                    -- Misma definición de cobrable que usa
                    -- SpaceBalanceService::compute para armar la lista que el
                    -- cajero elige — si divergieran, el split aceptaría ids que
                    -- la pantalla nunca ofrece.
                    AND oi.parentorderitemid IS NULL",
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

        return $amount;
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
                if (SpaceBalanceService::isCovered($balance)) {
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
     * La definición del saldo vive en SpaceBalanceService — la comparten este
     * service y SpaceSessionService::close(), que decide el cierre con el
     * mismo número.
     */
    private function computeBalance(string $companyId, string $sessionId): array
    {
        return $this->balances->compute($companyId, $sessionId);
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
