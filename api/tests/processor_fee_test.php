<?php
declare(strict_types=1);

// Guard anti falso-verde: DEBE ir antes de bootstrap.php (ver _harness.php).
require_once __DIR__ . '/_harness.php';

/**
 * Test de integración (Postgres real) de la comisión de procesadora por medio
 * de pago (`PaymentMethodService` feePercent/feeFixed + `FinanceLedger`).
 *
 * Qué protege:
 *
 *   (a) COBRO CON COMISIÓN: ingreso bruto + egreso "Comisión de procesadora"
 *       en la MISMA cuenta; saldo = neto (lo que acredita el banco).
 *   (b) TARIFA CONGELADA: cambiar la tarifa del medio después no toca el
 *       movimiento viejo; el cobro nuevo usa la nueva (% + fijo).
 *   (c) MEDIO SIN COMISIÓN: sin egreso.
 *   (d) PAGO DIVIDIDO: solo la línea del medio con comisión la genera.
 *   (e) IDEMPOTENCIA: re-correr el hook no duplica la comisión.
 *   (f) PAGO DE CRÉDITO: también descuenta comisión.
 *   (g) DEVOLUCIÓN: sale el neto devuelto, la comisión de la venta QUEDA
 *       (regla del owner 2026-09-18: la procesadora no la devuelve).
 *   (h) ANULACIÓN: se revierte el ingreso, la comisión queda; saldo final =
 *       −comisión respecto de antes de la venta.
 *   (i) REDONDEO a los decimales del tenant.
 *
 * Uso: ver `run_processor_fee_test.sh`.
 */

$companyIdConst = 'fee0c0de-0000-4000-8000-000000000101';
$outletIdConst  = 'fee0c0de-0000-4000-8000-000000000102';
$userIdConst    = 'fee0c0de-0000-4000-8000-000000000103';
$bankIdConst    = 'fee0c0de-0000-4000-8000-000000000104';

define('COMPANY_ID', $companyIdConst);
define('OUTLET_ID',  $outletIdConst);
define('USER_ID',    $userIdConst);

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Finance\AccountService;
use Punto\Api\Finance\CategoryService;
use Punto\Api\Finance\FinanceLedger;
use Punto\Api\PaymentMethods\PaymentMethodService;

$companyId = $companyIdConst;
$outletId  = $outletIdConst;
$userId    = $userIdConst;
$bankId    = $bankIdConst;

$failures = 0;
$checks   = 0;

function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void
{
    $checks++;
    if ($ok) {
        echo "OK   $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n     $detail\n";
}

function near(?float $a, ?float $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    return abs($a - $b) < 0.005;
}

// ── Fixture ──────────────────────────────────────────────────────────────────

global $db;

$db->Execute(
    "INSERT INTO company (companyId, status, plan, balance, isParent, config)
     VALUES (?, 'active', 1, 0.00, FALSE, ?::jsonb)
     ON CONFLICT (companyId) DO UPDATE SET config = EXCLUDED.config",
    [$companyId, json_encode([
        'settingName'              => 'Comision Procesadora Test',
        'settingDecimal'           => 'no',
        'settingThousandSeparator' => 'dot',
        'settingCountry'           => 'PY',
        'settingCurrency'          => 'PYG',
        'settingTimeZone'          => 'America/Asuncion',
        'settingTaxName'           => 'IVA',
        'settingLanguage'          => 'es',
        'settingSocialMedia'       => '{}',
        'settingObj'               => '{}',
    ])]
);
$db->Execute(
    "INSERT INTO outlet (outletId, outletName, outletStatus, companyId)
     VALUES (?, 'Comision Test - Sucursal', 1, ?)
     ON CONFLICT (outletId) DO UPDATE SET outletName = EXCLUDED.outletName",
    [$outletId, $companyId]
);

$db->Execute(
    "INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
     VALUES (?, 'Cajero test comision', ?, ?, 0, 1)
     ON CONFLICT (contactId) DO NOTHING",
    [$userId, $companyId, $outletId]
);

ncmExecute('DELETE FROM fin_movement WHERE companyid = ?', [$companyId]);
ncmExecute('DELETE FROM taxonomy WHERE companyId = ? AND taxonomyType = ?', [$companyId, 'paymentMethod']);
ncmExecute('DELETE FROM "transaction" WHERE companyId = ?', [$companyId]);

// La cuenta Efectivo del sistema primero: el seed de cuentas solo corre en un
// tenant sin NINGUNA cuenta.
$cashId = (new AccountService())->ensureCashAccountId($companyId);
ncmExecute('UPDATE fin_account SET currentbalance = 0 WHERE accountid = ?', [$cashId]);

$db->Execute(
    "INSERT INTO fin_account (accountid, companyid, name, type, openingbalance, currentbalance, outletid, issystem, status)
     VALUES (?, ?, 'Banco (test comision)', 'bank', 0, 0, ?, false, 1)
     ON CONFLICT (accountid) DO UPDATE SET currentbalance = 0, openingbalance = 0",
    [$bankId, $companyId, $outletId]
);
$pm = new PaymentMethodService($db);
$pm->list($companyId); // seed: Efectivo, tarjetas, etc.

$cardId = $pm->create($companyId, [
    'name'       => 'Tarjeta test',
    'feePercent' => '4',
    'feeFixed'   => '',
    'accountId'  => $bankId,
]);
$transferId = $pm->create($companyId, [
    'name'      => 'Transferencia test',
    'accountId' => $bankId,
]);
$cashMethodId = null;
foreach ($pm->list($companyId) as $m) {
    if (strcasecmp($m['name'], 'Efectivo') === 0) {
        $cashMethodId = $m['id'];
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function balance(string $accountId): float
{
    $r = ncmExecute('SELECT currentbalance FROM fin_account WHERE accountid = ?', [$accountId]);
    return (float) ($r['currentbalance'] ?? 0);
}

/** @return list<array<string,mixed>> */
function movements(string $companyId, string $source, string $sourceId): array
{
    $rs = ncmExecute(
        "SELECT movementid, accountid, categoryid, kind, amount, status, data::text AS datatxt
           FROM fin_movement WHERE companyid = ? AND source = ? AND sourceid = ?
          ORDER BY kind DESC, amount DESC",
        [$companyId, $source, $sourceId],
        false,
        true
    );
    $out = [];
    if ($rs && is_object($rs)) {
        while (!$rs->EOF) {
            $f = $rs->fields;
            $out[] = [
                'accountid'  => (string) $f['accountid'],
                'categoryid' => (string) ($f['categoryid'] ?? ''),
                'kind'       => (string) $f['kind'],
                'amount'     => (float) $f['amount'],
                'status'     => (int) $f['status'],
                'data'       => json_decode((string) $f['datatxt'], true) ?: [],
            ];
            $rs->MoveNext();
        }
        $rs->Close();
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function feeMovements(array $movs): array
{
    return array_values(array_filter($movs, static fn($m) => isset($m['data']['processorFee'])));
}

function insertTx(string $id, int $type, array $payments, string $companyId, string $outletId, string $userId, ?string $invoiceNo = null): void
{
    $total = 0.0;
    foreach ($payments as $p) {
        $total += (float) $p['price'];
    }
    ncmExecute(
        'INSERT INTO "transaction"
            (transactionid, transactiondate, transactiondiscount, transactiontax, transactiontotal,
             transactionpaymenttype, transactiontype, transactionuid, invoiceno, userid, outletid, companyid)
         VALUES (?, now(), 0, 0, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$id, $total, json_encode($payments), $type, 'fee-test-' . $id, $invoiceNo, $userId, $outletId, $companyId]
    );
}

$ledger  = new FinanceLedger();
$feeCat  = (new CategoryService())->ensureProcessorFeeCategoryId($companyId);

// ── (a) cobro con comisión ───────────────────────────────────────────────────

$sale1 = 'fee0c0de-0000-4000-8000-00000000a001';
insertTx($sale1, 0, [['type' => $cardId, 'name' => 'Tarjeta test', 'price' => 100000]], $companyId, $outletId, $userId, '1001');
$ledger->recordSale($companyId, $sale1);

$m1   = movements($companyId, 'sale', $sale1);
$fee1 = feeMovements($m1);
check('(a) 2 movimientos (ingreso + comisión)', count($m1) === 2, json_encode($m1), $failures, $checks);
check('(a) ingreso bruto 100.000', count($m1) > 0 && $m1[0]['kind'] === 'income' && near($m1[0]['amount'], 100000), json_encode($m1), $failures, $checks);
check('(a) egreso comisión 4.000 en la MISMA cuenta y categoría propia',
    count($fee1) === 1 && $fee1[0]['kind'] === 'expense' && near($fee1[0]['amount'], 4000)
        && $fee1[0]['accountid'] === $bankId && $fee1[0]['categoryid'] === $feeCat,
    json_encode($fee1), $failures, $checks);
check('(a) tarifa congelada en data (4% + 0)',
    count($fee1) === 1 && near((float) $fee1[0]['data']['processorFee']['lines'][0]['percent'], 4)
        && near((float) $fee1[0]['data']['processorFee']['lines'][0]['fixed'], 0),
    json_encode($fee1[0]['data'] ?? null), $failures, $checks);
check('(a) saldo banco = neto 96.000', near(balance($bankId), 96000), (string) balance($bankId), $failures, $checks);

// ── (e) idempotencia ─────────────────────────────────────────────────────────

$ledger->recordSale($companyId, $sale1);
check('(e) re-correr el hook no duplica', count(movements($companyId, 'sale', $sale1)) === 2 && near(balance($bankId), 96000),
    (string) balance($bankId), $failures, $checks);

// ── (b) tarifa cambiada después ──────────────────────────────────────────────

$pm->update($companyId, $cardId, ['feePercent' => '5', 'feeFixed' => '500']);
$fee1b = feeMovements(movements($companyId, 'sale', $sale1));
check('(b) movimiento viejo intacto (4.000, 4%)',
    count($fee1b) === 1 && near($fee1b[0]['amount'], 4000) && near((float) $fee1b[0]['data']['processorFee']['lines'][0]['percent'], 4),
    json_encode($fee1b), $failures, $checks);

$sale2 = 'fee0c0de-0000-4000-8000-00000000a002';
insertTx($sale2, 0, [['type' => $cardId, 'name' => 'Tarjeta test', 'price' => 12345]], $companyId, $outletId, $userId);
$ledger->recordSale($companyId, $sale2);
$fee2 = feeMovements(movements($companyId, 'sale', $sale2));
// 12345 × 5% = 617,25 + 500 = 1117,25 → 1117 (tenant sin decimales)
check('(b)(i) cobro nuevo con tarifa nueva, redondeado a los decimales del tenant',
    count($fee2) === 1 && near($fee2[0]['amount'], 1117), json_encode($fee2), $failures, $checks);

// ── (c) medio sin comisión ───────────────────────────────────────────────────

$sale3 = 'fee0c0de-0000-4000-8000-00000000a003';
insertTx($sale3, 0, [['type' => $transferId, 'name' => 'Transferencia test', 'price' => 50000]], $companyId, $outletId, $userId);
$ledger->recordSale($companyId, $sale3);
$m3 = movements($companyId, 'sale', $sale3);
check('(c) medio sin comisión: solo el ingreso', count($m3) === 1 && $m3[0]['kind'] === 'income' && feeMovements($m3) === [],
    json_encode($m3), $failures, $checks);

// ── (d) pago dividido ────────────────────────────────────────────────────────

$cashBefore = balance($cashId);
$bankBefore = balance($bankId);
$sale4 = 'fee0c0de-0000-4000-8000-00000000a004';
insertTx($sale4, 0, [
    ['type' => $cardId, 'name' => 'Tarjeta test', 'price' => 60000],
    ['type' => (string) $cashMethodId, 'name' => 'Efectivo', 'price' => 40000],
], $companyId, $outletId, $userId);
$ledger->recordSale($companyId, $sale4);
$m4   = movements($companyId, 'sale', $sale4);
$fee4 = feeMovements($m4);
// 60000 × 5% + 500 = 3500
check('(d) split: una sola comisión, del medio con tarifa (3.500 en el banco)',
    count($fee4) === 1 && near($fee4[0]['amount'], 3500) && $fee4[0]['accountid'] === $bankId,
    json_encode($m4), $failures, $checks);
check('(d) split: efectivo sin comisión (+40.000 exactos)', near(balance($cashId) - $cashBefore, 40000),
    (string) (balance($cashId) - $cashBefore), $failures, $checks);
check('(d) split: banco +56.500', near(balance($bankId) - $bankBefore, 56500),
    (string) (balance($bankId) - $bankBefore), $failures, $checks);

// ── (f) pago de crédito ──────────────────────────────────────────────────────

$pay1 = 'fee0c0de-0000-4000-8000-00000000a005';
insertTx($pay1, 5, [['type' => $cardId, 'name' => 'Tarjeta test', 'price' => 20000]], $companyId, $outletId, $userId);
$ledger->recordCreditPayment($companyId, $pay1);
$fee5 = feeMovements(movements($companyId, 'credit_payment', $pay1));
check('(f) pago de crédito con comisión (20.000 × 5% + 500 = 1.500)', count($fee5) === 1 && near($fee5[0]['amount'], 1500),
    json_encode($fee5), $failures, $checks);

// ── (g) devolución: la comisión de la venta queda ────────────────────────────

$bankBefore = balance($bankId);
$ret1 = 'fee0c0de-0000-4000-8000-00000000a006';
insertTx($ret1, 6, [['type' => $cardId, 'name' => 'Tarjeta test', 'price' => -12345]], $companyId, $outletId, $userId);
$ledger->recordReturn($companyId, $ret1);
$m6 = movements($companyId, 'return', $ret1);
check('(g) devolución: un egreso por el neto devuelto, sin tocar comisión',
    count($m6) === 1 && $m6[0]['kind'] === 'expense' && near($m6[0]['amount'], 12345) && feeMovements($m6) === [],
    json_encode($m6), $failures, $checks);
$fee2after = feeMovements(movements($companyId, 'sale', $sale2));
check('(g) comisión de la venta devuelta sigue activa',
    count($fee2after) === 1 && $fee2after[0]['status'] === 1, json_encode($fee2after), $failures, $checks);
check('(g) saldo: banco −12.345', near(balance($bankId) - $bankBefore, -12345),
    (string) (balance($bankId) - $bankBefore), $failures, $checks);

// ── (h) anulación: se revierte el ingreso, la comisión queda ─────────────────

$bankBefore = balance($bankId);
$sale7 = 'fee0c0de-0000-4000-8000-00000000a007';
insertTx($sale7, 0, [['type' => $cardId, 'name' => 'Tarjeta test', 'price' => 100000]], $companyId, $outletId, $userId);
$ledger->recordSale($companyId, $sale7);
// 100000 × 5% + 500 = 5500
check('(h) venta: banco +94.500', near(balance($bankId) - $bankBefore, 94500),
    (string) (balance($bankId) - $bankBefore), $failures, $checks);
$ledger->voidBySource($companyId, 'sale', $sale7);
$m7 = movements($companyId, 'sale', $sale7);
$income7 = array_values(array_filter($m7, static fn($m) => $m['kind'] === 'income'));
$fee7    = feeMovements($m7);
check('(h) ingreso anulado', count($income7) === 1 && $income7[0]['status'] === 0, json_encode($m7), $failures, $checks);
check('(h) comisión intacta', count($fee7) === 1 && $fee7[0]['status'] === 1, json_encode($m7), $failures, $checks);
check('(h) saldo final = −comisión (−5.500)', near(balance($bankId) - $bankBefore, -5500),
    (string) (balance($bankId) - $bankBefore), $failures, $checks);

// ── Limpieza ─────────────────────────────────────────────────────────────────

ncmExecute('DELETE FROM fin_movement WHERE companyid = ?', [$companyId]);
ncmExecute('DELETE FROM "transaction" WHERE companyId = ?', [$companyId]);

harnessFinish($failures, $checks);
