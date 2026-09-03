<?php
declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

/**
 * Arnés del FLUJO DE EFECTIVO reescrito (B1 de `context/60`).
 *
 * Lo que se verifica es el invariante que la versión anterior NO podía
 * satisfacer, y del que se derivan casi todos los casos:
 *
 *     saldo inicial + entradas − salidas = saldo final
 *
 * El reporte viejo se construía sobre `transaction` y (a) contaba como efectivo
 * cualquier venta de contado sin mirar el medio de pago, (b) usaba el neto del
 * período anterior como "saldo inicial". Los casos de acá lo cazarían.
 *
 * Uso (necesita Postgres migrado — ver run_cashflow_test.sh).
 */

$companyId = 'cf10e470-0000-4000-8000-000000000101';
$outletA   = 'cf10e470-0000-4000-8000-000000000102';
$outletB   = 'cf10e470-0000-4000-8000-000000000103';
$accCash   = 'cf10e470-0000-4000-8000-000000000104';
$accBank   = 'cf10e470-0000-4000-8000-000000000105';
$catVenta  = 'cf10e470-0000-4000-8000-000000000106';
$catAlq    = 'cf10e470-0000-4000-8000-000000000107';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletA);
define('USER_ID',    'cf10e470-0000-4000-8000-000000000108');

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Reports\CashflowService;

/** @var \Punto\Api\Database\Query $db */
global $db;

$failures = 0; $checks = 0;
function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void {
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++; echo "FAIL $label\n     $detail\n";
}

/** Alta de un movimiento. `amount` SIEMPRE positivo: el signo lo da `kind`. */
function mov(string $companyId, string $acc, ?string $cat, string $kind, float $amount,
             string $date, string $source = 'manual', int $status = 1, ?string $outlet = null): void {
    global $db;
    $db->Execute(
        "INSERT INTO fin_movement (companyid, accountid, categoryid, kind, amount, date, source, status, outletid)
         VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?::timestamptz, ?, ?, ?::uuid)",
        [$companyId, $acc, $cat, $kind, $amount, $date, $source, $status, $outlet]
    );
}

try {
    $db->Execute(
        "INSERT INTO company (companyId, status, plan, balance, isParent, config)
         VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Cashflow Test\"}'::jsonb)",
        [$companyId]
    );
    foreach ([$outletA, $outletB] as $oid) {
        $db->Execute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
            [$oid, 'CF Sucursal', $companyId]);
    }
    // Efectivo GLOBAL (outletid NULL) y banco atado a la sucursal A.
    $db->Execute("INSERT INTO fin_account (accountid, companyid, name, type, openingbalance, currentbalance, outletid, status)
                  VALUES (?::uuid, ?::uuid, 'Efectivo', 'cash', 100000, 0, NULL, 1)", [$accCash, $companyId]);
    $db->Execute("INSERT INTO fin_account (accountid, companyid, name, type, openingbalance, currentbalance, outletid, status)
                  VALUES (?::uuid, ?::uuid, 'Banco', 'bank', 50000, 0, ?::uuid, 1)", [$accBank, $companyId, $outletA]);
    foreach ([[$catVenta, 'Ventas', 'income'], [$catAlq, 'Alquiler', 'expense']] as [$cid, $cname, $ckind]) {
        $db->Execute("INSERT INTO fin_category (categoryid, companyid, name, kind, status)
                      VALUES (?::uuid, ?::uuid, ?, ?, 1)", [$cid, $companyId, $cname, $ckind]);
    }

    // ANTES del período: solo afecta el saldo de apertura.
    mov($companyId, $accCash, $catVenta, 'income',  30000, '2026-01-10 10:00:00');
    // DENTRO del período.
    mov($companyId, $accCash, $catVenta, 'income',  70000, '2026-02-05 10:00:00');
    mov($companyId, $accCash, $catAlq,   'expense', 25000, '2026-02-06 10:00:00');
    mov($companyId, $accBank, $catVenta, 'income',  40000, '2026-02-07 10:00:00');
    // ANULADO: no debe contar en ningún lado.
    mov($companyId, $accCash, $catVenta, 'income', 999999, '2026-02-08 10:00:00', 'manual', 0);
    // TRANSFERENCIA efectivo → banco: mueve saldos, NO es flujo de la empresa.
    mov($companyId, $accCash, null, 'expense', 10000, '2026-02-09 10:00:00', 'transfer');
    mov($companyId, $accBank, null, 'income',  10000, '2026-02-09 10:00:00', 'transfer');
    // SIN categoría: es plata real, tiene que entrar igual.
    mov($companyId, $accCash, null, 'income', 5000, '2026-02-10 10:00:00');
    // DESPUÉS del período: no debe aparecer.
    mov($companyId, $accCash, $catVenta, 'income', 888888, '2026-03-01 10:00:00');

    $svc = new CashflowService();
    $r = $svc->getCashFlow('2026-02-01 00:00:00', '2026-02-28 23:59:59', $companyId);

    // ── El invariante ────────────────────────────────────────────────────────
    check('el reporte CUADRA (inicial + entradas − salidas = final)',
        abs($r['balances']['check']) < 0.01,
        'check = ' . $r['balances']['check'], $failures, $checks);

    // Apertura: (100000 + 30000) efectivo + 50000 banco = 180000
    check('saldo inicial = apertura de cuentas + movimientos ANTERIORES',
        abs($r['balances']['opening'] - 180000) < 0.01,
        'opening = ' . $r['balances']['opening'] . ' (esperado 180000)', $failures, $checks);

    // Entradas del período SIN transferencias: 70000 + 40000 + 5000 = 115000
    check('entradas excluyen la transferencia entre cuentas propias',
        abs($r['incomeTotal'] - 115000) < 0.01,
        'incomeTotal = ' . $r['incomeTotal'] . ' (esperado 115000)', $failures, $checks);
    check('salidas excluyen la transferencia', abs($r['expenseTotal'] - 25000) < 0.01,
        'expenseTotal = ' . $r['expenseTotal'] . ' (esperado 25000)', $failures, $checks);

    // Cierre: 180000 + 115000 − 25000 = 270000 (la transferencia neta 0)
    check('saldo final refleja el neto real', abs($r['balances']['closing'] - 270000) < 0.01,
        'closing = ' . $r['balances']['closing'] . ' (esperado 270000)', $failures, $checks);

    check('el movimiento ANULADO no se cuenta',
        $r['incomeTotal'] < 999999, 'un status=0 entró al total', $failures, $checks);
    check('lo posterior al período no se cuenta',
        $r['incomeTotal'] < 888888, 'entró un movimiento fuera del rango', $failures, $checks);

    // ── Categorías ───────────────────────────────────────────────────────────
    $names = array_column($r['income'], 'name');
    check('agrupa por categoría', in_array('Ventas', $names, true),
        'categorías: ' . implode(',', $names), $failures, $checks);
    check('los movimientos sin categoría no se pierden',
        in_array('Sin categoría', $names, true),
        'omitirlos rompería el cuadre; categorías: ' . implode(',', $names), $failures, $checks);

    // ── Por cuenta ───────────────────────────────────────────────────────────
    $byAcc = [];
    foreach ($r['accounts'] as $a) { $byAcc[$a['name']] = $a; }
    check('el saldo POR CUENTA incluye la transferencia (sí mueve la cuenta)',
        abs($byAcc['Banco']['closing'] - 100000) < 0.01,
        'Banco closing = ' . ($byAcc['Banco']['closing'] ?? 'n/a') . ' (esperado 100000)',
        $failures, $checks);

    // ── Scope por sucursal ───────────────────────────────────────────────────
    $rb = $svc->getCashFlow('2026-02-01 00:00:00', '2026-02-28 23:59:59', $companyId, [$outletB]);
    $namesB = array_column($rb['accounts'], 'name');
    check('filtrando por otra sucursal, la cuenta GLOBAL sigue apareciendo',
        in_array('Efectivo', $namesB, true),
        'cuentas: ' . implode(',', $namesB), $failures, $checks);
    check('y la cuenta de la sucursal A no',
        !in_array('Banco', $namesB, true),
        'cuentas: ' . implode(',', $namesB), $failures, $checks);
} finally {
    $db->Execute('DELETE FROM fin_movement WHERE companyid = ?::uuid', [$companyId]);
    $db->Execute('DELETE FROM fin_category WHERE companyid = ?::uuid', [$companyId]);
    $db->Execute('DELETE FROM fin_account  WHERE companyid = ?::uuid', [$companyId]);
    $db->Execute('DELETE FROM outlet  WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM company WHERE companyId = ?', [$companyId]);
}

harnessFinish($failures, $checks);
