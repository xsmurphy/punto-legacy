<?php
/**
 * REST canónico (API compartida /api) — Resumen Anual de Ingresos y Egresos (raw).
 *
 *   GET /v1/reports/summary_year?y=<YYYY>
 *       → { year, years:[], months:[...] } CRUDO.
 *
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\SummaryYearService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$year = (string) (validateHttp('y') ?: date('Y'));
if (!preg_match('/^\d{4}$/', $year)) {
    apiError('Año inválido', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk($svc->yearly($year, $roc, (string) COMPANY_ID));
