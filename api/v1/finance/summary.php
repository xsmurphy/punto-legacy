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

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
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

// Rango del período. Una fecha SOLA en `to` significa el FINAL de ese día (ver
// Date::reportRange): con `to=2026-09-01` se perdía todo lo del 1 de septiembre
// posterior a la medianoche. El panel no lo pegaba porque su `rangeToBackend()`
// ya manda la hora; sí lo pegan el agente IA y los consumidores por API key.
// Un rango con formato inválido degrada al default (mes calendario actual) en
// vez de reventar el bind contra Postgres: mismo criterio que /v1/finance/reports.
[$from, $to, $rangeOk] = Date::reportRange(
    $_GET['from'] ?? '',
    $_GET['to'] ?? '',
    date('Y-m-01 00:00:00'),
);
if (!$rangeOk) {
    // Ver la nota de /v1/finance/reports: se degrada, pero deja rastro.
    error_log(sprintf(
        '[finance/summary] rango invalido, se degrada al mes actual: from=%s to=%s',
        (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? '')
    ));
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
