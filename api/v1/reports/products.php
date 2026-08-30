<?php
/**
 * REST canónico (API compartida /api) — Reporte de Artículos / Productos (raw).
 *
 *   GET /v1/reports/products?view=general|detail|combos&from=&to=
 *       [&cusId=&usrId=&itmId=&month=&year=&src=]
 *
 * Read-only. Sin formatear/HTML: el BFF calcula utilidad/KPIs/chart, el front formatea.
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'mcp']);
$svc = new \Punto\Api\Reports\ProductsService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

$view = (string) (validateHttp('view') ?: 'general');
if (!in_array($view, ['general', 'detail', 'combos'], true)) {
    apiError('Vista no soportada', 422);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

$uuidOrEmpty = function ($v) use ($uuidRe) {
    $v = (string) ($v ?: '');
    return ($v !== '' && preg_match($uuidRe, $v)) ? $v : '';
};

$filters = [
    'cusId' => $uuidOrEmpty(validateHttp('cusId')),
    'usrId' => $uuidOrEmpty(validateHttp('usrId')),
    'itmId' => $uuidOrEmpty(validateHttp('itmId')),
    'month' => (bool) validateHttp('month'),
    'year'  => (int) (validateHttp('year') ?: 0),
    'src'   => trim((string) (validateHttp('src') ?: '')),
];

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'detail') {
    apiOk($svc->detail($filters, $from, $to, $roc, $companyId));
} elseif ($view === 'combos') {
    apiOk($svc->combos($filters, $from, $to, $roc, $companyId));
} else {
    apiOk($svc->general($filters, $from, $to, $roc, $companyId));
}
