<?php
/**
 * REST canónico — Reporte de Artículos / Productos (motor ERP, raw).
 *
 *   GET /API/v1/reports/products?view=general|detail|combos&from=&to=
 *       [&cusId=&usrId=&itmId=&month=&year=&src=]   → datos CRUDOS según la vista.
 *
 * Read-only (el self-heal de tax del legacy se eliminó). Sin formatear/HTML: el BFF calcula
 * utilidad/KPIs/chart y el front formatea. Auth: JWT. Tenant por COMPANY_ID + roc. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportProductsService.php';

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

// Filtros opcionales. Los ids deben ser UUID válidos (o vacío).
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

$svc = new ReportProductsService();

if ($view === 'detail') {
    apiOk($svc->detail($filters, $from, $to));
} elseif ($view === 'combos') {
    apiOk($svc->combos($filters, $from, $to));
} else {
    apiOk($svc->general($filters, $from, $to));
}
