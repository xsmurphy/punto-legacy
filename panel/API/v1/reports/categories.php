<?php
/**
 * REST canónico — Reporte de Ventas por Categorías (motor ERP, raw).
 *
 *   GET /API/v1/reports/categories?from=&to=
 *       → filas crudas por categoría [{categoryId, name, usold, total, tax, cogs, comission, discount}].
 *
 * Sin formatear, sin HTML. El BFF calcula % + totales; el front formatea + arma tabla/KPIs/treemap.
 * Auth: JWT. Tenant por COMPANY_ID (roc b-prefijado para el JOIN con transaction). Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportCategoriesService.php';

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

// roc con el alias de transaction (b) — la query une itemSold a, transaction b, item c.
$roc = str_replace(['outletId', 'registerId', 'companyId'], ['b.outletId', 'b.registerId', 'b.companyId'], getROC(1));

$svc = new ReportCategoriesService();
apiOk($svc->salesByCategory($from, $to, $roc));
