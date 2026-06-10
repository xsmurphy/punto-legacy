<?php
/**
 * REST canónico (API compartida /api) — Reporte de Gift Cards (raw).
 *
 *   GET /v1/reports/giftcards?view=detail[&singleRow=]   → gift cards activadas, CRUDAS.
 *
 * Read-only. El form de edición y los writes siguen en el PHP legacy vía ?action= (migración
 * parcial: a_report_giftcards.php sigue activo para esas rutas via $bffPartialReports).
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\GiftcardsService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$view = (string) (validateHttp('view') ?: 'detail');
if ($view !== 'detail') {
    apiError('Vista no soportada', 422);
}

$singleRow = (string) (validateHttp('singleRow') ?: '');
if ($singleRow !== '' && !preg_match($uuidRe, $singleRow)) {
    $singleRow = '';
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk($svc->detail(['singleRow' => $singleRow], $roc, (string) COMPANY_ID));
