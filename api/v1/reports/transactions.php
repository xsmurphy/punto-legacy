<?php
/**
 * REST canónico (API compartida /api) — Reporte de Pagos y Transacciones (raw).
 *
 *   GET /v1/reports/transactions?view=detail|cobros|quotes&from=&to=
 *       [&cusId=&src=&singleRow=]
 *
 * SOLO las 3 vistas de LECTURA de BD. La vista feTable (API externa de FE), el CRUD de
 * edición y los reportes fiscales (rg90/libro-ventas/mcal/tusFacturas) + export siguen
 * en el panel legacy vía ?action=. Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\TransactionsService();

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

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'cobros') {
    apiOk($svc->cobros($filters, $from, $to, $roc, $companyId));
} elseif ($view === 'quotes') {
    apiOk($svc->quotes($filters, $from, $to, $roc, $companyId));
} else {
    apiOk($svc->detail($filters, $from, $to, $roc, $companyId));
}
