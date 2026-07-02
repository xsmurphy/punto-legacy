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

$ctx = apiAuthTenant(['panel']);
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

// Totales del período: reusamos MovementService::list con límite alto (no hay
// agregación dedicada todavía — Fase 1 alcanza con esto para el dashboard).
$period = $movementSvc->list($companyId, ['from' => $from, 'to' => $to, 'limit' => 500]);

$totalIncome = 0.0;
$totalExpense = 0.0;
foreach ($period['rows'] as $row) {
    if ($row['kind'] === 'income') {
        $totalIncome += $row['amount'];
    } else {
        $totalExpense += $row['amount'];
    }
}

$totalBalance = 0.0;
foreach ($accounts as $acc) {
    $totalBalance += $acc['currentBalance'];
}

$recent = $movementSvc->list($companyId, ['limit' => 10]);

apiOk([
    'accounts'      => $accounts,
    'totalBalance'  => $totalBalance,
    'period'        => ['from' => $from, 'to' => $to],
    'totalIncome'   => $totalIncome,
    'totalExpense'  => $totalExpense,
    'netFlow'       => $totalIncome - $totalExpense,
    'recentMovements' => $recent['rows'],
]);
