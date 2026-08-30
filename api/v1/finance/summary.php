<?php
/**
 * REST — Resumen/Dashboard de Finanzas.
 *
 *   GET /v1/finance/summary?from=&to= → saldos por cuenta + ingresos/egresos
 *                                        del período + últimos movimientos.
 *       from/to default: mes calendario actual (tenant-local, naive — §51).
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'mcp']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$companyId = (string) COMPANY_ID;

$accountSvc  = new \Punto\Api\Finance\AccountService();
$movementSvc = new \Punto\Api\Finance\MovementService();

$accounts = $accountSvc->list($companyId);

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
if ($from === '') {
    $from = date('Y-m-01 00:00:00');
}
if ($to === '') {
    $to = date('Y-m-d 23:59:59');
}

// Totales del período: agregación SQL real (SUM ... FILTER), no trunca con
// tenants de alto volumen como hacía sumar en PHP sobre list(limit=500).
$totals = $movementSvc->totalsByKind($companyId, $from, $to);

$totalBalance = 0.0;
foreach ($accounts as $acc) {
    $totalBalance += $acc['currentBalance'];
}

// Últimos movimientos del rango consultado (consistente con from/to del período).
$recent = $movementSvc->list($companyId, ['from' => $from, 'to' => $to, 'limit' => 10]);

apiOk([
    'accounts'      => $accounts,
    'totalBalance'  => $totalBalance,
    'period'        => ['from' => $from, 'to' => $to],
    'totalIncome'   => $totals['income'],
    'totalExpense'  => $totals['expense'],
    'netFlow'       => $totals['netFlow'],
    'recentMovements' => $recent['rows'],
]);
