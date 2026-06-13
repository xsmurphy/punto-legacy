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

// Roc::build con alias `c` para matchear el JOIN itemSold/item/transaction
// del service. Roc::build respeta VIEW_OUTLET_ID si está definida (override
// del dropdown del logo en panel-next, 2026-06-13).
$roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID, 'c');

apiOk($svc->salesByBrand($from, $to, $roc, COMPANY_ID));
