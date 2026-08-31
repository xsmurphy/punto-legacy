<?php
declare(strict_types=1);

require_once __DIR__ . '/_harness.php';

/**
 * Arnés del BALANCE GERENCIAL (B3 de `context/60`).
 *
 * El caso que más importa es el doble conteo: `ObligationsService` incluye
 * compras a crédito con `transactionComplete = false`, que es EXACTAMENTE lo que
 * ya cuenta `payables()` vía `OpenInvoicesService`. Sumar las dos duplicaría
 * cada compra en el pasivo — y con montos distintos, porque obligaciones
 * reporta el total del documento y cuentas por pagar el saldo neto de pagos.
 *
 * También se fija que el patrimonio es DERIVADO (Activo − Pasivo) y que el
 * reporte declara sus huecos en vez de disimularlos.
 */

$companyId = 'ba1a0470-0000-4000-8000-000000000101';
$outletId  = 'ba1a0470-0000-4000-8000-000000000102';
$userId    = 'ba1a0470-0000-4000-8000-000000000103';
$registerId= 'ba1a0470-0000-4000-8000-000000000104';
$supplier  = 'ba1a0470-0000-4000-8000-000000000105';
$customer  = 'ba1a0470-0000-4000-8000-000000000106';
$accCash   = 'ba1a0470-0000-4000-8000-000000000107';
$purchase  = 'ba1a0470-0000-4000-8000-000000000108';
$sale      = 'ba1a0470-0000-4000-8000-000000000109';

define('COMPANY_ID', $companyId);
define('OUTLET_ID',  $outletId);
define('USER_ID',    $userId);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Reports/BalanceService.php';

use Punto\Api\Reports\BalanceService;

/** @var \Punto\Api\Database\Query $db */
global $db;

$failures = 0; $checks = 0;
function check(string $label, bool $ok, string $detail, int &$failures, int &$checks): void {
    $checks++;
    if ($ok) { echo "OK   $label\n"; return; }
    $failures++; echo "FAIL $label\n     $detail\n";
}

try {
    $db->Execute("INSERT INTO company (companyId, status, plan, balance, isParent, config)
                  VALUES (?, 'active', 1, 0.00, FALSE, '{\"settingName\":\"Balance Test\"}'::jsonb)", [$companyId]);
    $db->Execute('INSERT INTO outlet (outletId, outletName, outletStatus, companyId) VALUES (?, ?, 1, ?)',
        [$outletId, 'BA Sucursal', $companyId]);
    $db->Execute('INSERT INTO register (registerId, registerName, registerStatus, outletId, companyId)
                  VALUES (?, ?, TRUE, ?, ?)', [$registerId, 'BA Caja', $outletId, $companyId]);
    foreach ([[$userId,'BA Usuario'],[$supplier,'BA Proveedor'],[$customer,'BA Cliente']] as [$cid,$cname]) {
        $db->Execute('INSERT INTO contact (contactId, contactName, companyId, outletId, type, contactStatus)
                      VALUES (?, ?, ?, ?, 0, 1)', [$cid, $cname, $companyId, $outletId]);
    }
    $db->Execute("INSERT INTO fin_account (accountid, companyid, name, type, openingbalance, currentbalance, outletid, status)
                  VALUES (?::uuid, ?::uuid, 'Efectivo', 'cash', 0, 500000, NULL, 1)", [$accCash, $companyId]);

    // Venta a crédito (type 3) sin pagar → cuenta por cobrar de 200.000
    $db->Execute("INSERT INTO transaction
        (transactionId, companyId, outletId, registerId, userId, customerId, transactionType,
         transactionStatus, transactionComplete, transactionTotal, transactionDiscount, transactionDate, transactionDueDate)
        VALUES (?, ?, ?, ?, ?, ?, 3, 1, FALSE, 200000, 0, NOW(), NOW() + INTERVAL '30 days')",
        [$sale, $companyId, $outletId, $registerId, $userId, $customer]);

    // Compra a crédito (type 4) sin pagar CON vencimiento → 300.000.
    // Es el caso del doble conteo: aparece en payables Y en ObligationsService.
    $db->Execute("INSERT INTO transaction
        (transactionId, companyId, outletId, registerId, userId, supplierId, transactionType,
         transactionStatus, transactionComplete, transactionTotal, transactionDiscount, transactionDate, transactionDueDate)
        VALUES (?, ?, ?, ?, ?, ?, 4, 1, FALSE, 300000, 0, NOW(), NOW() + INTERVAL '15 days')",
        [$purchase, $companyId, $outletId, $registerId, $userId, $supplier]);

    $r = (new BalanceService())->get($companyId, $outletId);

    check('efectivo sale de fin_account', abs($r['assets']['cash'] - 500000) < 0.01,
        'cash = ' . $r['assets']['cash'], $failures, $checks);
    check('cuentas por cobrar entran al activo', abs($r['assets']['receivables'] - 200000) < 0.01,
        'receivables = ' . $r['assets']['receivables'], $failures, $checks);
    check('cuentas por pagar entran al pasivo', abs($r['liabilities']['payables'] - 300000) < 0.01,
        'payables = ' . $r['liabilities']['payables'], $failures, $checks);

    // ── El caso central ──────────────────────────────────────────────────────
    check('la compra a crédito NO se cuenta dos veces',
        abs($r['liabilities']['total'] - 300000) < 0.01,
        'pasivo total = ' . $r['liabilities']['total'] . ' (esperado 300000; 600000 = doble conteo)',
        $failures, $checks);
    check('las obligaciones excluyen el tipo `purchase`',
        !array_key_exists('purchase', $r['liabilities']['obligationsByType']),
        'byType: ' . json_encode($r['liabilities']['obligationsByType']), $failures, $checks);

    // ── El patrimonio es derivado ────────────────────────────────────────────
    $esperado = $r['assets']['total'] - $r['liabilities']['total'];
    check('patrimonio = Activo − Pasivo, siempre', abs($r['equity'] - $esperado) < 0.01,
        'equity = ' . $r['equity'] . ' vs ' . $esperado, $failures, $checks);
    check('el activo total es la suma de sus rubros',
        abs($r['assets']['total'] - ($r['assets']['cash'] + $r['assets']['receivables'] + $r['assets']['inventory'])) < 0.01,
        'total = ' . $r['assets']['total'], $failures, $checks);

    // ── Declara sus huecos ───────────────────────────────────────────────────
    check('el reporte avisa que NO incluye activo fijo',
        ($r['notes']['missingFixedAssets'] ?? false) === true,
        'sin la nota, el patrimonio se lee como completo y está subestimado',
        $failures, $checks);
    check('y que es gerencial, no contable',
        ($r['notes']['managerial'] ?? false) === true, 'falta la marca', $failures, $checks);
    check('es una FOTO: trae asOf, no un rango', !empty($r['asOf']),
        'sin asOf no se sabe a qué momento corresponde', $failures, $checks);
} finally {
    $db->Execute('DELETE FROM transaction WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM fin_account WHERE companyid = ?::uuid', [$companyId]);
    $db->Execute('DELETE FROM contact  WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM register WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM outlet   WHERE companyId = ?', [$companyId]);
    $db->Execute('DELETE FROM company  WHERE companyId = ?', [$companyId]);
}

harnessFinish($failures, $checks);
