<?php
/**
 * REST canónico (API compartida /api) — Reporte de Ventas por Marca (raw).
 *
 *   GET /v1/reports/brands?from=&to= → filas crudas [{brandId, name, usold, total, tax, cogs, discount}]
 *
 * Sin formatear, sin HTML. El BFF compone % + subtotal + totales. Auth: realm `panel`.
 * Tenant por COMPANY_ID + outlet (ROC c-prefijado para el JOIN con transaction).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\BrandsService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

// ROC inline (reproduce getROC(1)) — companyId siempre, outlet si es UUID. Luego se
// prefija el alias `c` para que matchee el JOIN itemSold/item/transaction del service.
$rocBase = " AND companyId = '" . COMPANY_ID . "'";
if (preg_match($uuidRe, (string) OUTLET_ID)) {
    $rocBase .= " AND outletId = '" . OUTLET_ID . "'";
}
$roc = str_replace(
    ['outletId', 'registerId', 'companyId'],
    ['c.outletId', 'c.registerId', 'c.companyId'],
    $rocBase
);

apiOk($svc->salesByBrand($from, $to, $roc, COMPANY_ID));
