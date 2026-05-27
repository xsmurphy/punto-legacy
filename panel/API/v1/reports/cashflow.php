<?php
/**
 * REST canónico — Reporte de Flujo de Caja (motor ERP, raw).
 *
 *   GET /API/v1/reports/cashflow?from=&to=   → resumen de flujo de caja, CRUDO.
 *
 * Read-only. Sin formatear: el front formatea + arma KPIs + tabla. Auth: JWT. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportCashflowService.php';

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

$svc = new ReportCashflowService();
apiOk($svc->getCashFlow($from, $to));
