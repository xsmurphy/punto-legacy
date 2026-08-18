<?php
declare(strict_types=1);
namespace Punto\Api\Services;

use Punto\Api\Documents\DocumentNumber;

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

    /** 'YYYY-MM-DD' válido → tal cual; cualquier otra cosa (vacío, mal formada) → null. Nunca inventa un default. */
    private static function normalizeDate(?string $val): ?string
    {
        $val = $val !== null ? trim($val) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : null;
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
        bool    $isCustomer = true,
        ?array  $supplierDoc = null
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
            $paymentMethodKey, $note, $identifier, $supplierDoc
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
        ?string $identifier = null,
        ?array  $supplierDoc = null
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
            $paymentMethodKey, $note, $identifier, $supplierDoc
        );
    }

    /**
     * Anula un recibo de pago (type=5, `credit_payment` o `purchase_payment`)
     * — soft-void, MISMO criterio que `PurchasesService::void()` /
     * `PurchaseCreditNoteService::void()`: `transactionStatus = 6`, la fila
     * NUNCA se borra ni el correlativo (`invoiceNo`) se libera o reasigna.
     * Decisión del owner (2026-08-16, textual: "Está bien anular y que el
     * número no se pueda reusar, queda anulado para auditoría") — ver
     * `context/40-anulacion-y-nota-credito.md`.
     *
     * Por qué NO `transactionType = 7` (patrón de `TransactionService::
     * voidTransaction`, ventas): `TransactionLinkService::sumDerivedAmounts()`
     * / `mapSumDerivedAmounts()` ya excluyen derivados con
     * `COALESCE(transactionStatus, 1) <> 6` — ES el criterio de exclusión que
     * ya usan `PurchasesService`/`PurchaseCreditNoteService`. Usar el mismo
     * status acá hace que la deuda de las facturas afectadas se recalcule
     * SOLA, sin tocar `TransactionLinkService` (superficie compartida con
     * devoluciones y NC de compra — cambiar SU criterio de exclusión habría
     * afectado esas pantallas también).
     *
     * Reversa completa, dentro de UNA transacción:
     *   1. Lockea el recibo (`FOR UPDATE`) — si ya está `transactionStatus=6`,
     *      rechaza (idempotencia: no se anula dos veces, ver guard más abajo).
     *   2. Lockea TODAS las facturas que este recibo pagó (`transaction_link`
     *      origin, kind derivado de `customerId`/`supplierId` de la fila —
     *      nunca confiado del caller), mismo orden `transactionId ASC FOR
     *      UPDATE` que `create()`/`createDistributed()` (línea ~113/237) —
     *      evita deadlock con un cobro/pago concurrente sobre las mismas
     *      facturas.
     *   3. Marca el recibo `transactionStatus = 6`.
     *   4. Por cada factura afectada: recalcula `paid` con
     *      `sumDerivedAmounts()` (ya excluye el recibo recién anulado, misma
     *      TX) y `transactionComplete` según el saldo REAL — respeta que la
     *      factura pueda tener OTROS recibos vigentes (no asume "vuelve a
     *      impaga").
     *
     * El caller (endpoint) es responsable de: chequear permisos ANTES de
     * llamar (mismo criterio que gatea `create()`/`createDistributed()` mismo
     * tipo de contacto, ver `credit-payments.php`) y de revertir el movimiento
     * de caja post-commit (`FinanceLedger::voidBySource($companyId, $kind,
     * $paymentId)`), igual que `purchases.php` hace con `PurchasesService::
     * void()` — este método NO toca `fin_movement`.
     *
     * @return array{id:string,status:int,kind:string,affectedInvoices:list<array{transactionId:string,paid:float,debt:float,transactionComplete:bool}>}
     */
    public function void(string $paymentId, string $companyId, string $userId): array
    {
        global $db;

        if (!preg_match(self::UUID_RE, $paymentId)) {
            apiError('id de recibo inválido', 422);
        }

        $db->StartTrans();

        $payment = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? AND transactionType = 5 FOR UPDATE',
            [$paymentId, $companyId]
        );
        if (!$payment) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('Recibo no encontrado', 404);
        }
        // Idempotencia: un recibo ya anulado no se re-anula (ni "de nuevo" ni
        // se le puede imputar nada — create()/createDistributed() ya excluyen
        // facturas completas, pero acá el guard es sobre EL RECIBO mismo).
        if ((int) ($payment['transactionStatus'] ?? 1) === 6) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiError('El recibo ya fue anulado', 422);
        }

        $isCustomer = !empty($payment['customerId']);
        $kind       = $isCustomer ? 'credit_payment' : 'purchase_payment';

        // Facturas que este recibo pagó — el origen de los vínculos derivados
        // A este recibo (derivedid = $paymentId).
        $invoiceIds = $this->links->listOriginIds($companyId, $paymentId, $kind);

        $rows = [];
        if ($invoiceIds !== []) {
            $ph = implode(',', array_fill(0, count($invoiceIds), '?'));
            $rows = ncmExecute(
                "SELECT * FROM transaction WHERE transactionId IN ($ph) AND companyId = ?
                 ORDER BY transactionId ASC FOR UPDATE",
                array_merge($invoiceIds, [$companyId]), false, false, true
            );
            $rows = is_array($rows) ? $rows : [];
        }

        // Anular el recibo PRIMERO — dentro de la misma TX, sumDerivedAmounts()
        // ya lo excluye (COALESCE(transactionStatus,1)<>6) para el recalculo
        // de abajo. NO se pisa `responsibleId`: ese campo es quién cobró/pagó
        // ORIGINALMENTE (lo lee `TransactionDetailService` como
        // `responsibleName` en el detalle del recibo) — pisarlo con
        // `$userId` (quien anula) perdería esa atribución para siempre, igual
        // que `PurchasesService::void()`/`PurchaseCreditNoteService::void()`
        // tampoco lo tocan. "Quién anuló" no se registra hoy (no hay columna
        // `voidedBy` en el schema, mismo criterio que sus dos hermanos) — si
        // se necesita en el futuro es una columna nueva, no reusar esta.
        $db->Execute(
            'UPDATE transaction SET transactionStatus = 6 WHERE transactionId = ? AND companyId = ?',
            [$paymentId, $companyId]
        );

        $affected = [];
        foreach ($rows as $inv) {
            $invId = (string) $inv['transactionId'];
            $total = $isCustomer
                ? ((float) ($inv['transactionTotal'] ?? 0) - (float) ($inv['transactionDiscount'] ?? 0))
                : (float) ($inv['transactionTotal'] ?? 0);
            $paid  = $this->links->sumDerivedAmounts($companyId, $invId, $kind);
            $debt  = max(0.0, $total - $paid);
            // Recalculado desde cero — NO se asume "vuelve a impaga": si la
            // factura tenía otros recibos vigentes (ej. dos pagos parciales,
            // se anula uno), el saldo real puede seguir en 0.
            $complete = round($debt, 4) <= 0;
            $db->Execute(
                'UPDATE transaction SET transactionComplete = ' . ($complete ? 'TRUE' : 'FALSE') . '
                 WHERE transactionId = ? AND companyId = ?',
                [$invId, $companyId]
            );
            $affected[] = [
                'transactionId'       => $invId,
                'paid'                => $paid,
                'debt'                => $debt,
                'transactionComplete' => $complete,
            ];
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            apiError('No se pudo anular el recibo: la transacción abortó', 500);
        }

        try {
            realtimePublish('transaction', 'update', null);
        } catch (\Throwable $e) {
            // Ignorar — no crítico.
        }

        return [
            'id'               => $paymentId,
            'status'           => 6,
            'kind'             => $kind,
            'affectedInvoices' => $affected,
        ];
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
     * @param array{docPrefix?:?string,docNo?:?string,docDate?:?string,authNo?:?string,authNoDueDate?:?string}|null $supplierDoc
     *        Número de comprobante + timbrado IMPRESOS en el recibo del PROVEEDOR
     *        (context/29 §5) — solo tiene sentido cuando `$kind ===
     *        'purchase_payment'` (pagamos NOSOTROS, el papel lo emitió el
     *        proveedor). Para 'credit_payment' el recibo lo emitimos nosotros
     *        (invoiceNo propio, ver abajo) — `$supplierDoc` se ignora.
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
        ?string $identifier,
        ?array $supplierDoc = null
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
            // facturas cancela. Correlativo propio (docType 'recibo', scope
            // register — D2 de context/37) SOLO para cobro a cliente: la
            // factura de venta (type=3) siempre tiene registerId, nace en una
            // caja. El pago a proveedor (kind='purchase_payment') salda una
            // COMPRA (type=4) y `PurchasesService` NUNCA setea registerId —
            // solo outletId, porque las compras se cargan desde panel/
            // backoffice sin caja de por medio (`$parentRegisterId` quedaría
            // '' acá). Asignarle scope=register sería inventar un dato que no
            // existe; scope=outlet es candidato pero es decisión del owner
            // (impacta si el pago a proveedor es "documento fiscal" o
            // interno). Reportado, no resuelto en esta sesión — hasta esa
            // decisión, el pago a proveedor sigue con el helper legacy
            // (mismo comportamiento previo: invoiceNo siempre 0).
            'invoiceNo'              => $kind === 'credit_payment'
                ? DocumentNumber::allocate('recibo', DocumentNumber::SCOPE_REGISTER, $parentRegisterId, $companyId)
                : getNextDocNumber(0, 5, $companyId, $parentRegisterId),
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

        // Comprobante+timbrado del PROVEEDOR — solo pago a proveedor (ver
        // docblock de insertReceipt). NULLABLE, sin correlativo propio (owner
        // 2026-08-17: "capturar el número del proveedor", no generamos uno).
        if ($kind === 'purchase_payment' && $supplierDoc !== null) {
            $docNo = isset($supplierDoc['docNo']) && $supplierDoc['docNo'] !== '' ? (int) $supplierDoc['docNo'] : null;
            $tPay['supplierDocPrefix']     = (isset($supplierDoc['docPrefix']) && $supplierDoc['docPrefix'] !== '') ? (string) $supplierDoc['docPrefix'] : null;
            $tPay['supplierDocNo']         = $docNo;
            $tPay['supplierDocDate']       = self::normalizeDate($supplierDoc['docDate'] ?? null);
            $tPay['supplierAuthNo']        = (isset($supplierDoc['authNo']) && $supplierDoc['authNo'] !== '') ? (string) $supplierDoc['authNo'] : null;
            $tPay['supplierAuthNoDueDate'] = self::normalizeDate($supplierDoc['authNoDueDate'] ?? null);
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
