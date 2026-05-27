<?php
/**
 * REST canónico — Reporte de Compras y Gastos (motor ERP, raw).
 *
 *   GET /API/v1/reports/purchases?view=general|cobros|detail&from=&to=
 *       [&supId=&itmId=&singleRow=&src=]   → datos CRUDOS según la vista.
 *
 * SOLO las 3 vistas de LECTURA (el CRUD de edición y los fiscales rg90/libro-compra siguen
 * sirviéndose por el PHP legacy vía ?action=). Sin formatear/HTML: el front formatea.
 * Auth: JWT. Tenant por COMPANY_ID + roc. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportPurchasesService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

$view = (string) (validateHttp('view') ?: 'general');
if (!in_array($view, ['general', 'cobros', 'detail'], true)) {
    apiError('Vista no soportada', 422);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

// Filtros opcionales: ids deben ser UUID válidos (o vacío).
$uuidOrEmpty = function ($v) use ($uuidRe) {
    $v = (string) ($v ?: '');
    return ($v !== '' && preg_match($uuidRe, $v)) ? $v : '';
};

$filters = [
    'supId'     => $uuidOrEmpty(validateHttp('supId')),
    'itmId'     => $uuidOrEmpty(validateHttp('itmId')),
    'singleRow' => $uuidOrEmpty(validateHttp('singleRow')),
    'src'       => trim((string) (validateHttp('src') ?: '')),
];

$svc = new ReportPurchasesService();

if ($view === 'cobros') {
    apiOk($svc->cobros($filters, $from, $to));
} elseif ($view === 'detail') {
    apiOk($svc->detail($filters, $from, $to));
} else {
    apiOk($svc->general($filters, $from, $to));
}
