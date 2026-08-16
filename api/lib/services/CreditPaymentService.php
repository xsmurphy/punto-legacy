<?php
declare(strict_types=1);
namespace Punto\Api\Services;

/**
 * CreditPaymentService — registra cobros/pagos parciales o totales de crédito
 * (type=5): cobro de venta a crédito a un CLIENTE (`kind='credit_payment'`,
 * type=3 origen) o pago de compra a crédito a un PROVEEDOR
 * (`kind='purchase_payment'`, type=4 origen). Generalizado 2026-08 — antes
 * solo cubría clientes; el modelo (transaction_link kind='purchase_payment')
 * ya lo contemplaba desde la mig 115/122, pero no había service/endpoint/UI
 * que lo usara. Mismo mecanismo para los dos: un solo recibo (`transaction`
 * type=5) puede repartirse en N facturas del MISMO contacto, cada vínculo
 * con SU monto (mig 123) — nunca N recibos para un solo documento real.
 *
 * Replica la lógica de VPaymentService::settleCreditInvoice (clientes) pero
 * iniciado manualmente por el operador desde el POS o el panel.
 *
 * Invariantes de seguridad:
 *   - Todas las queries scopeadas por companyId (multi-tenant).
 *   - Las filas padre se bloquean con SELECT … FOR UPDATE DENTRO de la TX
 *     para evitar doble-cobro/doble-pago concurrente — tanto si el operador
 *     eligió las facturas a mano (`create()`) como si el server las eligió
 *     por FIFO (`createDistributed()`).
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
     * Registra UN recibo de pago, repartido en N facturas ELEGIDAS POR EL
     * OPERADOR (una puntual, o varias con montos manuales) del MISMO
     * contacto — mig 123, antes requería un recibo por factura.
     *
     * @param string $companyId        Tenant (siempre del JWT, nunca del body).
     * @param string $userId           Operador que cobra/paga (del JWT).
     * @param array  $allocations      list<{parentTransactionId: string, amount: float}> —
     *                                 al menos 1; duplicados por parentTransactionId se
     *                                 mergean (suman) antes de validar.
     * @param string $paymentMethodKey Key del método de pago (ej. "efectivo").
     * @param bool   $isCustomer       true = cobro a cliente (type=3, kind='credit_payment');
     *                                 false = pago a proveedor (type=4, kind='purchase_payment').
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
        ?string $identifier = null,
        bool    $isCustomer = true
    ): array {
        global $db;

        $type       = $isCustomer ? 3 : 4;
        $contactCol = $isCustomer ? 'customerId' : 'supplierId';
        $kind       = $isCustomer ? 'credit_payment' : 'purchase_payment';
        $contactLbl = $isCustomer ? 'cliente' : 'proveedor';

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
        // insertReceipt()) y el orden del array `allocations` de salida.
        $orderedParentIds = array_keys($merged);

        $db->StartTrans();

        // ── 2. Lock de TODAS las facturas padre en una sola query, ORDER BY
        //    transactionId ASC + FOR UPDATE: Postgres bloquea las filas en el
        //    orden en que las entrega el Sort subyacente a LockRows, así que
        //    ordenar por un criterio determinístico (el PK) evita deadlock
        //    entre dos requests concurrentes que cobren el mismo PAR de
        //    facturas en orden distinto. ncmExecute con getAssoc=true (NO
        //    forceObj — acá necesitamos el array completo ya materializado
        //    antes de seguir armando el resto de la TX).
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
        //    WHERE), mismo type, no completas, y MISMO contacto — un recibo
        //    es de un solo cliente/proveedor. ─────────────────────────────
        $contactId = (string) ($parents[$orderedParentIds[0]][$contactCol] ?? '');
        foreach ($orderedParentIds as $pid) {
            $p = $parents[$pid];
            if ((string) ($p['transactionType'] ?? '') !== (string) $type) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('Solo se puede pagar facturas a crédito (transactionId=' . $pid . ')', 422);
            }
            if ((int) ($p['transactionComplete'] ?? 0) === 1) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('Factura ya saldada: ' . $pid, 422);
            }
            if ((string) ($p[$contactCol] ?? '') !== $contactId) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError("Todas las facturas de un recibo deben ser del mismo {$contactLbl}", 422);
            }
        }

        // ── 4. Deuda de cada factura DENTRO de la TX (después del lock, con
        //    sumDerivedAmounts — mig 123, respeta `amount` de vínculos
        //    previos si esa factura ya recibió pagos parciales repartidos). ──
        $debts = [];
        foreach ($orderedParentIds as $pid) {
            $p     = $parents[$pid];
            // Cliente: total NETO de descuento. Proveedor: total crudo — misma
            // regla que OpenInvoicesService::general() (nunca una tercera fórmula).
            $total = $isCustomer
                ? ((float) ($p['transactionTotal'] ?? 0) - (float) ($p['transactionDiscount'] ?? 0))
                : (float) ($p['transactionTotal'] ?? 0);
            $paid  = $this->links->sumDerivedAmounts($companyId, $pid, $kind);
            $debt  = max(0.0, $total - $paid);
            $amt   = $merged[$pid];
            if (round($amt, 4) > round($debt, 4) + 0.001) {
                $db->FailTrans();
                $db->CompleteTrans();
                apiError('El monto imputado a la factura ' . $pid . ' supera su deuda actual', 422);
            }
            $debts[$pid] = ['total' => $total, 'paid' => $paid, 'debt' => $debt];
        }

        return $this->insertReceipt(
            $companyId, $userId, $kind, $contactCol, $contactId,
            $orderedParentIds, $parents, $debts, $merged,
            $paymentMethodKey, $note, $identifier
        );
    }

    /**
     * "Monto libre" — el operador entrega UN monto total y EL SERVIDOR decide
     * cómo se reparte entre las facturas abiertas del contacto: de la más
     * VIEJA a la más nueva (`transactionDueDate ASC`), saldando cada una
     * completa hasta donde alcance — la última que toca puede quedar parcial.
     * Mismo recibo único + N vínculos que `create()`; la única diferencia es
     * quién decide las allocations (acá, este método — nunca el cliente).
     *
     * Ej.: deuda de 3 facturas (100, 200, 300) y `$amount=250` → la primera
     * queda saldada (100), la segunda parcial en 150 (200-150=50 pendiente),
     * la tercera intacta (300 pendiente). Ver test en
     * `api/tests/credit_payment_distribution_test.php`.
     *
     * @throws — vía apiError() 422 si el contacto no tiene deuda abierta o si
     *           `$amount` supera la deuda total (nunca se acepta sobrepago
     *           silencioso: o se imputa completo a facturas reales, o se
     *           rechaza explícitamente).
     */
    public function createDistributed(
        string  $companyId,
        string  $userId,
        string  $contactId,
        bool    $isCustomer,
        float   $amount,
        string  $paymentMethodKey,
        ?string $note = null,
        ?string $identifier = null
    ): array {
        global $db;

        if ($amount <= 0) {
            apiError('El monto debe ser mayor a 0', 422);
        }
        if (!preg_match(self::UUID_RE, $contactId)) {
            apiError('contactId inválido', 422);
        }

        $type       = $isCustomer ? 3 : 4;
        $contactCol = $isCustomer ? 'customerId' : 'supplierId';
        $kind       = $isCustomer ? 'credit_payment' : 'purchase_payment';

        $db->StartTrans();

        // Lock de TODAS las facturas abiertas del contacto, ORDER BY
        // transactionId ASC — MISMO criterio de lock que create() (línea
        // ~113). Postgres bloquea las filas en el orden que entrega el Sort
        // subyacente a LockRows: si create() y createDistributed() lockearan
        // en órdenes distintos (ej. acá por transactionDueDate), dos
        // requests concurrentes sobre el MISMO contacto — una cobrando una
        // factura puntual, otra con "monto libre" — podrían tomar locks
        // cruzados y Postgres abortaría una por deadlock (40P01). El orden
        // FIFO (más vieja primero) para el REPARTO se aplica después, en
        // memoria, sobre las filas ya lockeadas — no en el SELECT.
        $rows = ncmExecute(
            "SELECT * FROM transaction
             WHERE transactionComplete = false AND transactionType = ? AND companyId = ? AND $contactCol = ?
             ORDER BY transactionId ASC
             FOR UPDATE",
            [$type, $companyId, $contactId], false, false, true
        );
        $rows = is_array($rows) ? $rows : [];
        // FIFO real para el reparto: más vieja primero (NULLs de dueDate al
        // final), desempate por transactionId para determinismo — se ordena
        // ACÁ, en PHP, después de tener el lock, no en el SELECT.
        usort($rows, static function ($a, $b) {
            $dueA = $a['transactionDueDate'] ?? null;
            $dueB = $b['transactionDueDate'] ?? null;
            if ($dueA === $dueB) {
                return strcmp((string) $a['transactionId'], (string) $b['transactionId']);
            }
            if ($dueA === null) {
                return 1;
            }
            if ($dueB === null) {
                return -1;
            }
            return strcmp((string) $dueA, (string) $dueB);
        });

        $parents = [];
        $debts   = [];
        $totalDebt = 0.0;
        // Orden de iteración de $rows ya es FIFO (usort de arriba) — se
        // preserva armando $merged en ese mismo orden más abajo.
        foreach ($rows as $r) {
            $pid   = (string) $r['transactionId'];
            $total = $isCustomer
                ? ((float) ($r['transactionTotal'] ?? 0) - (float) ($r['transactionDiscount'] ?? 0))
                : (float) ($r['transactionTotal'] ?? 0);
            $paid  = $this->links->sumDerivedAmounts($companyId, $pid, $kind);
            $debt  = max(0.0, $total - $paid);
            if ($debt <= 0.0001) {
                // Ya saldada (el snapshot del reporte que originó el diálogo
                // pudo quedar corto) — no entra en el reparto.
                continue;
            }
            $parents[$pid] = $r;
            $debts[$pid]   = ['total' => $total, 'paid' => $paid, 'debt' => $debt];
            $totalDebt    += $debt;
        }

        if ($debts === []) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('El contacto no tiene facturas a crédito pendientes', 422);
        }

        // Sin sobrepago silencioso: si el monto entregado supera la deuda
        // total del contacto, se rechaza explícito — nunca se imputa de más
        // a la última factura ni queda un resto sin destino.
        if (round($amount, 4) > round($totalDebt, 4) + 0.001) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError(
                'El monto (' . number_format($amount, 2) . ') supera la deuda total del contacto (' .
                number_format($totalDebt, 2) . ')',
                422
            );
        }

        // Reparto FIFO: recorre en el orden ya bloqueado (más vieja primero),
        // satura cada deuda hasta donde alcanza el remanente. Algoritmo puro
        // extraído a distributeFifo() — testeado sin DB en
        // api/tests/credit_payment_distribution_test.php.
        $merged = self::distributeFifo(array_map(static fn ($d) => $d['debt'], $debts), $amount);
        $orderedParentIds = array_keys($merged);

        return $this->insertReceipt(
            $companyId, $userId, $kind, $contactCol, $contactId,
            $orderedParentIds, $parents, $debts, $merged,
            $paymentMethodKey, $note, $identifier
        );
    }

    /**
     * Reparto FIFO puro, sin DB ni side-effects: dado un mapa YA ORDENADO
     * (más vieja primero) pid => deuda, y un monto a repartir, devuelve
     * pid => monto imputado — se corta apenas se agota el remanente, así que
     * puede devolver MENOS entradas que `$debtsByPid` (las que no llegaron a
     * tocarse no aparecen). No valida sobrepago (`$amount` > suma de deudas)
     * — esa validación vive en `createDistributed()`, antes de llamar acá,
     * porque ahí sí importa el mensaje de error exacto.
     *
     * Extraído como método puro (estático, testeable sin conexión a DB) para
     * poder correr el caso numérico del owner sin levantar el service
     * completo — ver `api/tests/credit_payment_distribution_test.php`
     * (ejecutable directo: `php api/tests/credit_payment_distribution_test.php`).
     *
     * @param array<string,float> $debtsByPid pid => deuda pendiente, YA en orden FIFO.
     * @return array<string,float> pid => monto imputado (subset de $debtsByPid, mismo orden).
     */
    public static function distributeFifo(array $debtsByPid, float $amount): array
    {
        $merged = [];
        $remaining = $amount;
        foreach ($debtsByPid as $pid => $debt) {
            if ($remaining <= 0.0001) {
                break;
            }
            $applied = round(min($remaining, $debt), 2);
            if ($applied <= 0) {
                continue;
            }
            $merged[$pid] = $applied;
            $remaining -= $applied;
        }
        return $merged;
    }

    /**
     * Cola compartida de `create()`/`createDistributed()`: inserta EL recibo
     * (1 fila `transaction` type=5) + un vínculo `transaction_link` por
     * factura con SU monto (mig 123), actualiza `transactionComplete` de las
     * que quedaron saldadas, y comitea. Asume que el caller YA abrió la TX
     * (`$db->StartTrans()`), ya bloqueó (`FOR UPDATE`) las filas en
     * `$parents`, y ya validó que `$merged` no supera `$debts` de ninguna.
     *
     * Único lugar que arma la fila del recibo y linkea — antes de esta
     * extracción, `create()` tenía esta lógica inline hardcodeada a cliente;
     * generalizarla acá (parametrizada por `$kind`/`$contactCol`) es lo que
     * permite que `createDistributed()` (y un futuro caller de proveedores)
     * la reusen sin reimplementar el insert+link+complete.
     *
     * @param array<string,\CaseInsensitiveArray|array> $parents  pid => fila completa (para registerId/outletId/responsibleId del PRIMERO).
     * @param array<string,array{total:float,paid:float,debt:float}> $debts pid => deuda ANTES de este pago.
     * @param array<string,float> $merged pid => monto a imputar (ya validado <= debt).
     */
    private function insertReceipt(
        string $companyId,
        string $userId,
        string $kind,
        string $contactCol,
        string $contactId,
        array  $orderedParentIds,
        array  $parents,
        array  $debts,
        array  $merged,
        string $paymentMethodKey,
        ?string $note,
        ?string $identifier
    ): array {
        global $db;

        // Resolver nombre del método de pago server-side (nunca confiar en el body).
        $paymentMethodName = getPaymentMethodName($paymentMethodKey);
        $totalAmount = array_sum($merged);

        // registerId/outletId/responsibleId del recibo: se toman de la
        // PRIMERA factura de la lista (orden de llegada / FIFO según el
        // caller). Elección deliberada — con varias facturas no hay un único
        // register "correcto"; se picha el de la primera.
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
            // facturas cancela. Sin numeración correlativa propia — mismo
            // criterio ya documentado para `credit_payment` (context/37-
            // numeracion-documentos.md, tabla D2: "Recibo (pago de crédito) |
            // transaction type 5 | sin numeración") — un pago a proveedor es
            // el mismo tipo de documento (recibo interno, no factura fiscal
            // del SET), así que hereda la misma decisión, no una nueva.
            'invoiceNo'              => getNextDocNumber(0, 5, $companyId, $parentRegisterId),
            'timestamp'              => time(),
            $contactCol              => $contactId,
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

        // Un link POR FACTURA, cada uno con SU monto (mig 123).
        $resultAllocations = [];
        foreach ($orderedParentIds as $pid) {
            $amt = $merged[$pid];
            $this->links->link($companyId, $pid, $newId, $kind, $amt);

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
            // enc($newId) — la transacción type=5 (recibo) recién creada, en
            // el formato que espera el BFF `/pos/transactions/[id]` (dec()
            // server-side).
            'encId'          => enc($newId),
            'amount'         => $totalAmount,
            'parentComplete' => array_reduce($resultAllocations, static fn ($carry, $a) => $carry && $a['parentComplete'], true),
            'paid'           => $debts[$orderedParentIds[0]]['paid'] + $first['amount'],
            'debtRemaining'  => array_sum(array_column($resultAllocations, 'debtRemaining')),
            'allocations'    => $resultAllocations,
        ];
    }
}
