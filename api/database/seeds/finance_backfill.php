<?php
declare(strict_types=1);

/**
 * Backfill histórico de `fin_movement` (Finanzas Fase 3).
 *
 * Recorre la operación real ya guardada (ventas, pagos de crédito, compras,
 * extracciones/ingresos de caja) de UN tenant y llama los mismos `record*`
 * de `FinanceLedger` que usan los hooks en vivo — así el ledger derivado
 * queda consistente con lo que se hubiera generado si Fase 3 hubiera estado
 * activa desde el día 1.
 *
 * Idempotente: reejecutable sin duplicar — confía en el UNIQUE
 * (companyid, source, sourceid, accountid) WHERE sourceid IS NOT NULL (mig 73)
 * que ya usa `MovementService::recordDerivedMovement()`.
 *
 * Uso CLI:
 *   php api/database/seeds/finance_backfill.php <companyId>
 *   FINANCE_BACKFILL_COMPANY_ID=<companyId> php api/database/seeds/finance_backfill.php
 *
 * También expuesto vía POST /v1/finance/backfill (endpoint panel,
 * finance.manage) para el botón "Importar histórico" del frontend — ese
 * endpoint hace require de este archivo como función, ver backfillCompany().
 */

// Permitir tanto "php finance_backfill.php <id>" (CLI) como require desde el
// endpoint HTTP (que ya cargó bootstrap.php y setea $__financeBackfillNoCli).
if (!defined('FINANCE_BACKFILL_NO_BOOTSTRAP')) {
    require_once __DIR__ . '/../../bootstrap.php';
}

use Punto\Api\Finance\FinanceLedger;

/**
 * Corre el backfill completo para un tenant. Devuelve contadores.
 *
 * @return array{sales:int,creditPayments:int,purchases:int,returns:int,drawerExpenses:int,drawerIncomes:int,errors:int}
 */
function financeBackfillCompany(string $companyId): array
{
    $ledger = new FinanceLedger();
    $counts = [
        'sales' => 0, 'creditPayments' => 0, 'purchases' => 0, 'returns' => 0,
        'drawerExpenses' => 0, 'drawerIncomes' => 0, 'errors' => 0,
    ];

    // Ventas al contado (type=0) + pagos de crédito (type=5) + compras (type=1)
    // + devoluciones (type=6). Excluye anuladas (type=7) — voidTransaction ya
    // las marcó, no queremos generar movimientos para transacciones canceladas.
    $rs = ncmExecute(
        "SELECT transactionId, transactionType FROM transaction
          WHERE companyId = ? AND transactionType IN ('0','1','5','6')
          ORDER BY transactionDate ASC",
        [$companyId],
        false,
        true
    );
    if ($rs && is_object($rs)) {
        while (!$rs->EOF) {
            $f    = $rs->fields;
            $txId = (string) ($f['transactionid'] ?? $f['transactionId'] ?? '');
            $type = (string) ($f['transactiontype'] ?? $f['transactionType'] ?? '');
            try {
                if ($type === '0') {
                    $ledger->recordSale($companyId, $txId);
                    $counts['sales']++;
                } elseif ($type === '5') {
                    $ledger->recordCreditPayment($companyId, $txId);
                    $counts['creditPayments']++;
                } elseif ($type === '1') {
                    $ledger->recordPurchase($companyId, $txId);
                    $counts['purchases']++;
                } elseif ($type === '6') {
                    $ledger->recordReturn($companyId, $txId);
                    $counts['returns']++;
                }
            } catch (\Throwable $e) {
                $counts['errors']++;
                error_log("[finance_backfill] transactionId={$txId} type={$type}: " . $e->getMessage());
            }
            $rs->MoveNext();
        }
        $rs->Close();
    }

    // Extracciones/ingresos de caja (`expenses`, type NULL = extracción, 1 = ingreso).
    $rsExp = ncmExecute(
        'SELECT expensesId, type FROM expenses WHERE companyId = ? ORDER BY expensesDate ASC',
        [$companyId],
        false,
        true
    );
    if ($rsExp && is_object($rsExp)) {
        while (!$rsExp->EOF) {
            $f          = $rsExp->fields;
            $expensesId = (string) ($f['expensesid'] ?? $f['expensesId'] ?? '');
            $type       = $f['type'] ?? null;
            try {
                if ((int) ($type ?? 0) === 1) {
                    $ledger->recordDrawerIncome($companyId, $expensesId);
                    $counts['drawerIncomes']++;
                } else {
                    $ledger->recordDrawerExpense($companyId, $expensesId);
                    $counts['drawerExpenses']++;
                }
            } catch (\Throwable $e) {
                $counts['errors']++;
                error_log("[finance_backfill] expensesId={$expensesId}: " . $e->getMessage());
            }
            $rsExp->MoveNext();
        }
        $rsExp->Close();
    }

    return $counts;
}

// ── Entry point CLI ──────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && !defined('FINANCE_BACKFILL_NO_BOOTSTRAP')) {
    $companyId = $argv[1] ?? getenv('FINANCE_BACKFILL_COMPANY_ID') ?: '';
    $companyId = trim((string) $companyId);

    if ($companyId === '') {
        fwrite(STDERR, "Uso: php finance_backfill.php <companyId>\n");
        exit(1);
    }

    $before = ncmExecute('SELECT COUNT(*) AS n FROM fin_movement WHERE companyid = ?', [$companyId]);
    $countBefore = (int) ($before['n'] ?? 0);

    $counts = financeBackfillCompany($companyId);

    $after = ncmExecute('SELECT COUNT(*) AS n FROM fin_movement WHERE companyid = ?', [$companyId]);
    $countAfter = (int) ($after['n'] ?? 0);

    fwrite(STDOUT, "Finanzas backfill — companyId={$companyId}\n");
    fwrite(STDOUT, "  Ventas procesadas:        {$counts['sales']}\n");
    fwrite(STDOUT, "  Pagos de crédito:         {$counts['creditPayments']}\n");
    fwrite(STDOUT, "  Compras procesadas:       {$counts['purchases']}\n");
    fwrite(STDOUT, "  Devoluciones procesadas:  {$counts['returns']}\n");
    fwrite(STDOUT, "  Extracciones de caja:     {$counts['drawerExpenses']}\n");
    fwrite(STDOUT, "  Ingresos de caja:         {$counts['drawerIncomes']}\n");
    fwrite(STDOUT, "  Errores (ver error_log):  {$counts['errors']}\n");
    fwrite(STDOUT, "  Movimientos en fin_movement: {$countBefore} -> {$countAfter} (nuevos: " . ($countAfter - $countBefore) . ")\n");
}
