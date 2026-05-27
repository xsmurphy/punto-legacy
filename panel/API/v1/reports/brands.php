<?php
/**
 * REST canónico — Reporte de Ventas por Marca (motor ERP, raw).
 *
 *   GET /API/v1/reports/brands?from=&to=
 *       → filas crudas por marca [{brandId, name, usold, total, tax, cogs, discount}].
 *
 * Sin formatear, sin HTML. El BFF calcula % + subtotal + totales. Auth: JWT. Tenant por
 * COMPANY_ID (roc c-prefijado para el JOIN con transaction). Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportBrandsService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

// roc con el alias de transaction (c) — la query une itemSold a, item b, transaction c.
$roc = str_replace(['outletId', 'registerId', 'companyId'], ['c.outletId', 'c.registerId', 'c.companyId'], getROC(1));

$svc = new ReportBrandsService();
apiOk($svc->salesByBrand($from, $to, $roc));
