<?php
declare(strict_types=1);

namespace Punto\Api\Finance;

/**
 * Auto-poblado de `fin_movement` desde la operación real (Fase 3).
 *
 * Cada método re-lee la fila de origen por id — así el MISMO código sirve
 * para el hook post-commit en vivo Y para el backfill histórico
 * (`api/database/seeds/finance_backfill.php`). Todo es best-effort e
 * idempotente: los call-sites en vivo lo envuelven en try/catch para nunca
 * romper la operación real (venta/compra/caja) si Finanzas falla.
 *
 * Pago dividido (split-payment): una venta puede traer varias líneas de pago
 * (`transactionPaymentType`), cada una resolviendo a una cuenta distinta
 * (Efectivo, Tarjeta, etc). Regla: 1 movimiento por (origen, cuenta
 * resuelta) — se agrupan/suman las líneas que caen en la misma cuenta.
 * Idempotencia vía UNIQUE (companyid, source, sourceid, accountid) — mig 73.
 *
 * Multi-tenant: $companyId siempre explícito (§33.2). Columnas físicas
 * lowercase sin comillas (bug case-sensitivity mig 71).
 */
final class FinanceLedger
{
    private MovementService $movements;
    private AccountService $accounts;
    private CategoryService $categories;
    private ConfigService $config;

    public function __construct()
    {
        $this->movements  = new MovementService();
        $this->accounts   = new AccountService();
        $this->categories = new CategoryService();
        $this->config     = new ConfigService();
    }

    /**
     * Venta al contado (transactionType=0). Las ventas a crédito (type=3) NO
     * generan movimiento acá — se genera cuando se cobran (recordCreditPayment).
     */
    public function recordSale(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '0') {
            return;
        }

        $categoryId = $this->categories->ensureSalesCategoryId($companyId);
        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Venta' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        // F1 cheques (context/30): el cheque nace del pago — si alguna línea
        // usa el método con systemKey='check', crea el fin_check (pending)
        // ANTES de recordPaymentLines. Independiente de esa función: el
        // cheque nace siempre, tenga o no la línea una cuenta mapeada.
        $this->createCheckFromLines(
            $companyId,
            $transactionId,
            $this->decodePaymentLines($row),
            'received',
            $this->nullableUuid($row['customerId'] ?? null),
        );

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'sale',
            sourceId: $transactionId,
            row: $row,
            kind: 'income',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /** Pago de una factura a crédito (transactionType=5). */
    public function recordCreditPayment(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '5') {
            return;
        }

        $categoryId = $this->categories->ensureSalesCategoryId($companyId);
        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Pago de crédito' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        // F1 cheques (context/30) — ver comentario espejo en recordSale().
        $this->createCheckFromLines(
            $companyId,
            $transactionId,
            $this->decodePaymentLines($row),
            'received',
            $this->nullableUuid($row['customerId'] ?? null),
        );

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'credit_payment',
            sourceId: $transactionId,
            row: $row,
            kind: 'income',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /**
     * Pago a proveedor de una compra a crédito (transactionType=5, mismo tipo
     * que `credit_payment` — se distinguen por `supplierId` seteado en vez de
     * `customerId`, igual que `CreditPaymentService::create()` los arma).
     * Espejo de `recordCreditPayment()` con el signo opuesto: acá SALE plata
     * de la caja (kind='expense'), no entra.
     */
    public function recordPurchasePayment(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '5') {
            return;
        }

        $categoryId = $this->categories->ensurePurchasesCategoryId($companyId);
        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Pago a proveedor' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        // F1 cheques (context/30) — pago a proveedor con cheque = cheque
        // EMITIDO por el comercio (espejo de recordPurchase()).
        $this->createCheckFromLines(
            $companyId,
            $transactionId,
            $this->decodePaymentLines($row),
            'issued',
            $this->nullableUuid($row['supplierId'] ?? null),
        );

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'purchase_payment',
            sourceId: $transactionId,
            row: $row,
            kind: 'expense',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /** Compra al contado (transactionType=1). */
    public function recordPurchase(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        // Solo compras al CONTADO (type 1) generan egreso al crearse. Una
        // compra a CRÉDITO (type 4) no mueve plata todavía: es una cuenta por
        // pagar, y el movimiento nace cuando se registra el pago a proveedor
        // (type 5, vinculado vía transaction_link kind='purchase_payment', mig
        // 115). Sin este corte el crédito
        // debitaría la caja dos veces (al comprar y al pagar).
        if ((string) ($row['transactionType'] ?? '') !== '1') {
            return;
        }

        $categoryId = $this->categories->ensurePurchasesCategoryId($companyId);
        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Compra' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        $lines = $this->decodePaymentLines($row);
        if (empty($lines)) {
            // Sin líneas de pago detalladas: dato legacy previo a la mig 102
            // (Parte 2 del incidente 2026-07 — 198 compras, ~737M debitados
            // erróneamente de Efectivo por un fallback que asumía cash — ya
            // resuelta: el form de compra persiste método+cuenta como ventas,
            // y `_getTableSchema()` ya no enruta transactionPaymentType al
            // JSONB `meta`). NO hay una cuenta real a la que imputar acá — se
            // salta el movimiento en vez de adivinar.
            error_log("[FinanceLedger] recordPurchase: compra sin línea de pago (legacy pre-102) — transactionId={$transactionId}");
            return;
        }

        // F1 cheques (context/30) — ver comentario espejo en recordSale().
        // Compra pagada con cheque = cheque EMITIDO por el comercio.
        $this->createCheckFromLines(
            $companyId,
            $transactionId,
            $lines,
            'issued',
            $this->nullableUuid($row['supplierId'] ?? null),
        );

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'purchase',
            sourceId: $transactionId,
            row: $row,
            kind: 'expense',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /**
     * Devolución (transactionType=6). Sale plata de la caja → kind='expense'.
     *
     * ReturnService persiste la línea de pago en NEGATIVO (`total` = -neto);
     * `recordPaymentLines` toma abs() y el signo contable lo define $kind, así
     * que el movimiento queda como egreso por el neto devuelto.
     *
     * Devolución acreditada al cliente (`refundMode='credit'` → línea
     * `type='storeCredit'`): NO genera movimiento. No sale plata de ninguna
     * cuenta — sube `contact.contactStoreCredit`, que es un pasivo con el
     * cliente, no caja. El filtro vive en `recordPaymentLines` (compartido con
     * ventas: una venta pagada con crédito interno / puntos / gift card tampoco
     * mueve plata).
     */
    public function recordReturn(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '6') {
            return;
        }

        // El nro de comprobante de una devolución vive en la venta original
        // (la fila type=6 se inserta sin invoiceNo) — se trae por el origen
        // (transaction_link kind='return', mig 115, reemplaza transactionParentId)
        // para que el movimiento sea rastreable hasta la venta que se devolvió.
        $parentId = (new \Punto\Api\Services\TransactionLinkService())->listOriginIds($companyId, $transactionId, 'return')[0] ?? null;
        $invoiceNo = '';
        if ($parentId !== null) {
            $parent = ncmExecute(
                'SELECT invoiceNo FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
                [$parentId, $companyId]
            );
            $invoiceNo = $parent ? (string) ($parent['invoiceNo'] ?? '') : '';
        }

        $categoryId = $this->categories->ensureReturnsCategoryId($companyId);
        $description = 'Devolución' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'return',
            sourceId: $transactionId,
            row: $row,
            kind: 'expense',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /**
     * Nota de crédito de compra en modo 'cash' (transactionType=14). El
     * proveedor nos devuelve plata → entra a la cuenta como INGRESO. Modo
     * 'credit' (reduce saldo pendiente de una compra a crédito, no mueve
     * caja) nunca llega acá con datos: `PurchaseCreditNoteService::create()`
     * no escribe `transactionPaymentType` en ese modo, así que
     * `decodePaymentLines`/`recordPaymentLines` no encuentran nada que
     * registrar y el método es un no-op — mismo mecanismo de filtro que usa
     * `recordReturn` para su `refundMode='credit'`.
     */
    public function recordPurchaseCreditNote(string $companyId, string $transactionId): void
    {
        $row = ncmExecute(
            'SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$row) {
            return;
        }
        if ((string) ($row['transactionType'] ?? '') !== '14') {
            return;
        }

        // El nro de comprobante de la compra original vive en la compra padre
        // (la fila type=14 se inserta sin invoiceNo) — se trae por el origen
        // (transaction_link kind='purchase_credit_note', mig 122) para que el
        // movimiento sea rastreable hasta la compra que se acreditó.
        $parentId = (new \Punto\Api\Services\TransactionLinkService())->listOriginIds($companyId, $transactionId, 'purchase_credit_note')[0] ?? null;
        $invoiceNo = '';
        if ($parentId !== null) {
            $parent = ncmExecute(
                'SELECT invoiceNo FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
                [$parentId, $companyId]
            );
            $invoiceNo = $parent ? (string) ($parent['invoiceNo'] ?? '') : '';
        }

        $categoryId  = $this->categories->ensurePurchaseCreditNoteCategoryId($companyId);
        $description = 'Nota de crédito compra' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        $this->recordPaymentLines(
            companyId: $companyId,
            source: 'purchase_credit_note',
            sourceId: $transactionId,
            row: $row,
            kind: 'income',
            categoryId: $categoryId,
            description: $description,
        );
    }

    /**
     * Extracción de caja (`expenses`, type NULL = extracción). $expensesId es
     * el id de la fila insertada — DrawerService::addExpense debe pasarlo
     * (usa ncmInsert en vez de $db->Execute raw para poder recuperarlo).
     */
    public function recordDrawerExpense(string $companyId, string $expensesId): void
    {
        $this->recordDrawerRow($companyId, $expensesId, 'expense');
    }

    /** Ingreso de caja (`expenses`, type=1). */
    public function recordDrawerIncome(string $companyId, string $expensesId): void
    {
        $this->recordDrawerRow($companyId, $expensesId, 'income');
    }

    /**
     * Anula (soft-void) todos los movimientos derivados de un origen +
     * revierte el saldo. Se llama cuando la operación de origen se anula
     * (venta/compra → transactionStatus=6, etc).
     */
    public function voidBySource(string $companyId, string $source, string $sourceId): void
    {
        $this->movements->voidBySource($companyId, $source, $sourceId);
    }

    // ── internos ──────────────────────────────────────────────────────────

    private function recordDrawerRow(string $companyId, string $expensesId, string $kind): void
    {
        $row = ncmExecute(
            'SELECT * FROM expenses WHERE expensesId = ? AND companyId = ? LIMIT 1',
            [$expensesId, $companyId]
        );
        if (!$row) {
            return;
        }
        $amount = abs((float) ($row['expensesAmount'] ?? 0));
        if ($amount <= 0) {
            return;
        }
        $accountId = $this->accounts->ensureCashAccountId($companyId);
        $categoryId = $kind === 'income'
            ? $this->categories->ensureSalesCategoryId($companyId)
            : $this->categories->ensurePurchasesCategoryId($companyId);

        $this->movements->recordDerivedMovement($companyId, 'expense', $expensesId, [
            'accountId'     => $accountId,
            'categoryId'    => $categoryId,
            'kind'          => $kind,
            'amount'        => $amount,
            'date'          => (string) ($row['expensesDate'] ?? ''),
            'description'   => (string) ($row['expensesDescription'] ?? '') ?: ($kind === 'income' ? 'Ingreso de caja' : 'Extracción de caja'),
            'paymentMethod' => 'efectivo',
            'userId'        => $this->nullableUuid($row['userId'] ?? null),
            'outletId'      => $this->nullableUuid($row['outletId'] ?? null),
        ]);
    }

    /**
     * Decodifica `transactionPaymentType`, agrupa por cuenta resuelta y
     * crea 1 movimiento por (origen, cuenta) con el monto sumado. Núcleo del
     * soporte de pago dividido.
     */
    private function recordPaymentLines(
        string $companyId,
        string $source,
        string $sourceId,
        array|\CaseInsensitiveArray $row,
        string $kind,
        ?string $categoryId,
        string $description
    ): void {
        $lines = $this->decodePaymentLines($row);
        if (empty($lines)) {
            return;
        }

        $date      = (string) ($row['transactionDate'] ?? '');
        $userId    = $this->nullableUuid($row['userId'] ?? null);
        $outletId  = $this->nullableUuid($row['outletId'] ?? null);

        // Agrupar por accountId: [accountId => ['amount' => sum, 'methodKey' => representativo]]
        $byAccount = [];
        foreach ($lines as $line) {
            $methodKey = (string) ($line['type'] ?? $line['name'] ?? '');
            if ($this->isNonCashMethod($companyId, $methodKey)) {
                continue;
            }
            $amount = abs((float) ($line['price'] ?? $line['total'] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            $accountId = $this->config->resolveAccountId($companyId, $methodKey);

            if (!isset($byAccount[$accountId])) {
                $byAccount[$accountId] = ['amount' => 0.0, 'methodKey' => $methodKey];
            }
            $byAccount[$accountId]['amount'] += $amount;
        }

        foreach ($byAccount as $accountId => $agg) {
            if ($agg['amount'] <= 0) {
                continue;
            }
            $this->movements->recordDerivedMovement($companyId, $source, $sourceId, [
                'accountId'     => $accountId,
                'categoryId'    => $categoryId,
                'kind'          => $kind,
                'amount'        => $agg['amount'],
                'date'          => $date,
                'description'   => $description,
                'paymentMethod' => $agg['methodKey'] ?: null,
                'userId'        => $userId,
                'outletId'      => $outletId,
            ]);
        }
    }

    /**
     * Medios que NO mueven plata de una cuenta real DIRECTAMENTE desde la
     * línea de pago: crédito interno del cliente, puntos de loyalty y gift
     * card (la plata entró cuando se vendió la gift card, no cuando se
     * canjea) — mismo error de clase que el fallback viejo de
     * `recordPurchase` (incidente 2026-07). El vocabulario es el de
     * `Reports\NonAddingSales` (ventas que no suman).
     *
     * `check` (F1, context/30) entra en la misma lista por una razón
     * distinta: la plata del cheque NO es real hasta que se efectiviza
     * (`CheckService::changeStatus` a 'cleared') — si la línea de pago
     * generara un movimiento acá TAMBIÉN, el cheque duplicaría el ingreso/
     * egreso (una vez al nacer, otra al aclarar). Exclusión incondicional,
     * tenga o no `finAccountMap` asignado — nunca "cae a Efectivo".
     *
     * Aplica a TODOS los orígenes (venta, pago de crédito, compra,
     * devolución): la regla es del wrapper, no del call-site. En
     * devoluciones es lo que hace que `refundMode='credit'` no toque la caja.
     */
    private const NON_CASH_METHODS = ['storecredit', 'incredit', 'points', 'giftcard', 'check'];

    /**
     * La clave del pago puede venir como slug legacy ('storeCredit') o como
     * taxonomyId del método (ventas nuevas) — para el segundo caso el
     * vocabulario estable es el `systemKey` del método.
     */
    private function isNonCashMethod(string $companyId, string $methodKey): bool
    {
        $key = strtolower(trim($methodKey));
        if ($key === '') {
            return false;
        }
        if (in_array($key, self::NON_CASH_METHODS, true)) {
            return true;
        }

        $resolver = new \Punto\Api\PaymentMethods\PaymentMethodResolver();
        $methodId = $resolver->resolveMethodId($companyId, $methodKey);
        if ($methodId === null) {
            return false;
        }
        foreach ($resolver->methods($companyId) as $method) {
            if (strcasecmp($method['id'], $methodId) === 0) {
                $systemKey = strtolower((string) ($method['systemKey'] ?? ''));
                return $systemKey !== '' && in_array($systemKey, self::NON_CASH_METHODS, true);
            }
        }
        return false;
    }

    /**
     * F1 cheques (context/30): si alguna línea de pago corresponde al método
     * con `systemKey='check'`, crea el `fin_check` asociado (pending). A lo
     * sumo un cheque por transacción — el primer match gana (mismo criterio
     * que el UNIQUE `(companyid, transactionid)` de `fin_check`, mig 102); un
     * pago dividido con dos líneas "cheque" no está soportado en v1.
     *
     * Best-effort por línea: un fallo acá NUNCA bloquea el resto del ledger
     * (movimiento derivado de la venta/compra sigue su curso normal).
     *
     * @param array<int,array<string,mixed>> $lines
     */
    private function createCheckFromLines(
        string $companyId,
        string $transactionId,
        array $lines,
        string $direction,
        ?string $contactId
    ): void {
        foreach ($lines as $line) {
            $methodKey = (string) ($line['type'] ?? $line['name'] ?? '');
            if (!$this->isCheckMethod($companyId, $methodKey)) {
                continue;
            }
            try {
                (new CheckService())->createFromPayment(
                    companyId: $companyId,
                    transactionId: $transactionId,
                    line: $line,
                    direction: $direction,
                    contactId: $contactId,
                );
            } catch (\Throwable $e) {
                error_log("[FinanceLedger] createCheckFromLines falló para transactionId={$transactionId}: " . $e->getMessage());
            }
            return; // a lo sumo un cheque por transacción
        }
    }

    /** Análogo a isNonCashMethod pero discrimina específicamente el método Cheque. */
    private function isCheckMethod(string $companyId, string $methodKey): bool
    {
        $key = strtolower(trim($methodKey));
        if ($key === '') {
            return false;
        }
        if ($key === 'check' || $key === 'cheque') {
            return true;
        }

        $resolver = new \Punto\Api\PaymentMethods\PaymentMethodResolver();
        $methodId = $resolver->resolveMethodId($companyId, $methodKey);
        if ($methodId === null) {
            return false;
        }
        foreach ($resolver->methods($companyId) as $method) {
            if (strcasecmp($method['id'], $methodId) === 0) {
                return strtolower((string) ($method['systemKey'] ?? '')) === 'check';
            }
        }
        return false;
    }

    /** @return array<int,array<string,mixed>> */
    private function decodePaymentLines(array|\CaseInsensitiveArray $row): array
    {
        $raw = $row['transactionPaymentType'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        // Shape esperado: array de objetos. Si viniera un solo objeto (legacy),
        // normalizamos a lista de 1.
        if (isset($decoded['type']) || isset($decoded['name'])) {
            return [$decoded];
        }
        return array_values(array_filter($decoded, 'is_array'));
    }

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private function nullableUuid($val): ?string
    {
        $val = (string) ($val ?? '');
        return ($val !== '' && preg_match(self::UUID_RE, $val)) ? $val : null;
    }
}
