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
        if ((string) ($row['transactionType'] ?? '') !== '1') {
            return;
        }

        $categoryId = $this->categories->ensurePurchasesCategoryId($companyId);
        $invoiceNo  = (string) ($row['invoiceNo'] ?? '');
        $description = 'Compra' . ($invoiceNo !== '' ? " {$invoiceNo}" : '');

        $lines = $this->decodePaymentLines($row);
        if (empty($lines)) {
            // Sin líneas de pago detalladas: 1 movimiento por transactionTotal,
            // cuenta Efectivo (fallback seguro — nunca perder el movimiento).
            $total = abs((float) ($row['transactionTotal'] ?? 0));
            if ($total <= 0) {
                return;
            }
            // Auditoría: si HABÍA un transactionPaymentType pero no se pudo
            // decodificar (JSON malformado), el dinero cae en Efectivo por
            // fallback — logueamos para que un backfill sea auditable y no
            // atribuya silenciosamente a la cuenta equivocada.
            $rawPay = $row['transactionPaymentType'] ?? null;
            if (is_string($rawPay) && trim($rawPay) !== '') {
                error_log("[FinanceLedger] recordPurchase: transactionPaymentType no decodificable para transactionId={$transactionId} — fallback a Efectivo por total={$total}");
            }
            $accountId = $this->accounts->ensureCashAccountId($companyId);
            $this->movements->recordDerivedMovement($companyId, 'purchase', $transactionId, [
                'accountId'     => $accountId,
                'categoryId'    => $categoryId,
                'kind'          => 'expense',
                'amount'        => $total,
                'date'          => (string) ($row['transactionDate'] ?? ''),
                'description'   => $description,
                'paymentMethod' => 'efectivo',
                'userId'        => $this->nullableUuid($row['userId'] ?? null),
                'outletId'      => $this->nullableUuid($row['outletId'] ?? null),
            ]);
            return;
        }

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
        array $row,
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
            $amount = abs((float) ($line['price'] ?? $line['total'] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            $methodKey = (string) ($line['type'] ?? $line['name'] ?? '');
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

    /** @return array<int,array<string,mixed>> */
    private function decodePaymentLines(array $row): array
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
