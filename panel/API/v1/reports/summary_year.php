<?php
/**
 * REST canónico — Resumen Anual de Ingresos y Egresos (motor ERP, raw).
 *
 *   GET /API/v1/reports/summary_year?y=<YYYY>  → { year, years:[], months:[...] } CRUDO.
 *       Si y se omite, usa el año en curso. El BFF deriva netTotal/revenue/margen + promedio;
 *       el front formatea, mapea mes→nombre y arma tabla + chart.
 *
 * Sin formatear, sin HTML. Auth: JWT. Tenant por COMPANY_ID + roc. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportSummaryYearService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$year = (string) (validateHttp('y') ?: date('Y'));
if (!preg_match('/^\d{4}$/', $year)) {
    apiError('Año inválido', 422);
}

$roc = getROC(1);
$svc = new ReportSummaryYearService();
apiOk($svc->yearly($year, $roc, COMPANY_ID));
