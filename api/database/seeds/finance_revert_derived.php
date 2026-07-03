<?php
declare(strict_types=1);

/**
 * Revert de movimientos derivados de `fin_movement` (Finanzas Fase 3) +
 * recompute de saldos de `fin_account`.
 *
 * Motivo (incidente 2026-07): `FinanceLedger::recordPurchase` debitaba el
 * `transactionTotal` completo de la cuenta Efectivo cuando la compra no
 * tenía medio de pago detallado (`transactionPaymentType` vacío — el flujo
 * de compras nunca capturó con qué cuenta se pagó). El backfill histórico
 * corrió ese fallback sobre 198 compras y dejó Efectivo en saldo negativo
 * (~737M debitados erróneamente). Decisión del owner: las compras sin
 * cuenta real NO deben imputarse a Efectivo — se revierte todo lo derivado
 * y se rehace el backfill con `FinanceLedger::recordPurchase` corregido
 * (ver commit que salta el movimiento cuando no hay líneas de pago).
 *
 * Qué hace, en una transacción:
 *   1. Borra los movimientos derivados (`source` IN sale/purchase/credit_payment)
 *      del tenant — NO toca 'manual', 'expense', 'transfer', 'opening', 'check'.
 *   2. Recomputa `currentbalance` de CADA cuenta del tenant desde
 *      `openingbalance` + suma de movimientos activos (status=1) que
 *      queden en `fin_movement` tras el borrado.
 *
 * Idempotente: reejecutable sin efectos adicionales (el DELETE con el mismo
 * filtro no vuelve a encontrar filas la segunda vez; el recompute siempre
 * corrige al mismo valor derivado del estado actual de fin_movement).
 *
 * Uso CLI:
 *   php api/database/seeds/finance_revert_derived.php <companyId>
 *   FINANCE_REVERT_COMPANY_ID=<companyId> php api/database/seeds/finance_revert_derived.php
 */

if (!defined('FINANCE_REVERT_NO_BOOTSTRAP')) {
    require_once __DIR__ . '/../../bootstrap.php';
}

/**
 * Revierte los movimientos derivados de un tenant y recomputa saldos.
 * Todo dentro de una única transacción DB (atómico).
 *
 * @return array{deletedMovements:int,accounts:array<int,array{accountId:string,name:string,openingBalance:float,currentBalance:float}>}
 */
function financeRevertDerived(string $companyId): array
{
    global $db;

    $db->StartTrans();

    // 1) Borra movimientos derivados de venta/compra/pago de crédito del tenant.
    //    Scopeado por companyid — nunca toca otros tenants.
    $before = ncmExecute(
        "SELECT COUNT(*) AS n FROM fin_movement
          WHERE companyid = ? AND source IN ('sale', 'purchase', 'credit_payment')",
        [$companyId]
    );
    $deletedCount = (int) ($before['n'] ?? 0);

    ncmExecute(
        "DELETE FROM fin_movement
          WHERE companyid = ? AND source IN ('sale', 'purchase', 'credit_payment')",
        [$companyId]
    );

    // 2) Recomputa el saldo de cada cuenta del tenant: openingbalance + Σ
    //    movimientos activos (status=1) que quedan en fin_movement tras el
    //    borrado. income suma, expense resta. Scopeado por companyid en
    //    ambas tablas — nunca toca cuentas de otros tenants.
    ncmExecute(
        "UPDATE fin_account
            SET currentbalance = openingbalance + COALESCE((
                  SELECT sum(CASE WHEN m.kind = 'income' THEN m.amount ELSE -m.amount END)
                    FROM fin_movement m
                   WHERE m.accountid = fin_account.accountid
                     AND m.companyid = fin_account.companyid
                     AND m.status = 1
                ), 0)
          WHERE companyid = ?",
        [$companyId]
    );

    $failed = $db->HasFailedTrans();
    $db->CompleteTrans();
    if ($failed) {
        throw new \RuntimeException('finance_revert_derived: la transacción falló, revertido');
    }

    $accountsRs = ncmExecute(
        'SELECT accountid, name, openingbalance, currentbalance FROM fin_account
          WHERE companyid = ? ORDER BY issystem DESC, name ASC',
        [$companyId],
        false,
        true
    );
    $accounts = [];
    if ($accountsRs && is_object($accountsRs)) {
        while (!$accountsRs->EOF) {
            $f = $accountsRs->fields;
            $accounts[] = [
                'accountId'      => (string) ($f['accountid'] ?? ''),
                'name'           => (string) ($f['name'] ?? ''),
                'openingBalance' => (float) ($f['openingbalance'] ?? 0),
                'currentBalance' => (float) ($f['currentbalance'] ?? 0),
            ];
            $accountsRs->MoveNext();
        }
        $accountsRs->Close();
    }

    return [
        'deletedMovements' => $deletedCount,
        'accounts'         => $accounts,
    ];
}

// ── Entry point CLI ──────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && !defined('FINANCE_REVERT_NO_BOOTSTRAP')) {
    $companyId = $argv[1] ?? getenv('FINANCE_REVERT_COMPANY_ID') ?: '';
    $companyId = trim((string) $companyId);

    if ($companyId === '') {
        fwrite(STDERR, "Uso: php finance_revert_derived.php <companyId>\n");
        exit(1);
    }

    $result = financeRevertDerived($companyId);

    fwrite(STDOUT, "Finanzas revert derived — companyId={$companyId}\n");
    fwrite(STDOUT, "  Movimientos borrados (sale/purchase/credit_payment): {$result['deletedMovements']}\n");
    fwrite(STDOUT, "  Saldos recomputados por cuenta:\n");
    foreach ($result['accounts'] as $acc) {
        fwrite(STDOUT, sprintf(
            "    - %-30s opening=%14.2f current=%14.2f (id=%s)\n",
            $acc['name'],
            $acc['openingBalance'],
            $acc['currentBalance'],
            $acc['accountId']
        ));
    }
    error_log("[finance_revert_derived] companyId={$companyId} deletedMovements={$result['deletedMovements']}");
}
