<?php
/**
 * REST canónico (API compartida /api) — Reporte de Compras y Gastos (raw).
 *
 *   GET  /v1/reports/purchases?view=general|cobros|detail&from=&to=
 *        [&supId=&itmId=&singleRow=&src=]
 *   POST /v1/reports/purchases (action=deletePayment&id=…)
 *
 * Las 3 vistas de LECTURA + borrado de pagos a proveedor.
 * El CRUD de edición y los fiscales siguen en panel legacy.
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx    = apiAuthTenant(['panel']);
$svc    = new \Punto\Api\Reports\PurchasesService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

/* ───────── write: eliminar pago a proveedor ───────── */
if ($method === 'POST') {
    if ((int) $ctx['roleId'] === 7) {
        apiError('Sin permiso para esta acción', 403);
    }
    $action = (string) (validateHttp('action', 'post') ?: '');
    if ($action !== 'deletePayment') {
        apiError('Acción no soportada', 422);
    }
    $id = (string) (validateHttp('id', 'post') ?: '');
    if (!preg_match($uuidRe, $id)) {
        apiError('id inválido', 422);
    }
    $parentRaw = (string) (validateHttp('parent', 'post') ?: '');
    $parentId  = ($parentRaw !== '' && preg_match($uuidRe, $parentRaw)) ? $parentRaw : null;
    if (!$svc->deletePayment($id, $parentId, (string) COMPANY_ID)) {
        apiError('No se pudo eliminar', 500);
    }
    apiOk(['id' => $id, 'action' => 'deletePayment']);
}

if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}
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

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'cobros') {
    apiOk($svc->cobros($filters, $from, $to, $roc, $companyId));
} elseif ($view === 'detail') {
    apiOk($svc->detail($filters, $from, $to, $roc, $companyId));
} else {
    apiOk($svc->general($filters, $from, $to, $roc, $companyId));
}
