<?php
/**
 * REST canónico (API compartida /api) — Reporte de Flujo de Caja (raw).
 *
 *   GET /v1/reports/cashflow?from=&to=  → resumen de flujo de caja, CRUDO.
 *
 * Read-only. Sin formatear. Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\CashflowService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk($svc->getCashFlow($from, $to, $roc, (string) COMPANY_ID));
