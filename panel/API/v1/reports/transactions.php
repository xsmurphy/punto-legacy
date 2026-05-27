<?php
/**
 * REST canónico — Reporte de Pagos y Transacciones (motor ERP, raw).
 *
 *   GET /API/v1/reports/transactions?view=detail|cobros|quotes&from=&to=
 *       [&cusId=&src=&singleRow=]   → datos CRUDOS según la vista.
 *
 * SOLO las 3 vistas de LECTURA de BD (detail/cobros/quotes). La vista `feTable` (API externa
 * de Facturación Electrónica), el CRUD de edición y los fiscales (rg90/libro-ventas/mcal/
 * tusFacturas) + export siguen sirviéndose por el PHP legacy vía ?action=. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportTransactionsService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

$view = (string) (validateHttp('view') ?: 'detail');
if (!in_array($view, ['detail', 'cobros', 'quotes'], true)) {
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
    'cusId'     => $uuidOrEmpty(validateHttp('cusId')),
    'singleRow' => $uuidOrEmpty(validateHttp('singleRow')),
    'src'       => trim((string) (validateHttp('src') ?: '')),
];

$svc = new ReportTransactionsService();

if ($view === 'cobros') {
    apiOk($svc->cobros($filters, $from, $to));
} elseif ($view === 'quotes') {
    apiOk($svc->quotes($filters, $from, $to));
} else {
    apiOk($svc->detail($filters, $from, $to));
}
