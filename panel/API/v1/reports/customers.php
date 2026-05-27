<?php
/**
 * REST canónico — Reporte de Clientes (motor ERP, raw).
 *
 *   GET /API/v1/reports/customers?from=&to=  → { rows: [...] } CRUDO (un ítem por cliente).
 *       El BFF deriva neto/promedio + totales + chart; el front formatea y arma tabla/KPIs.
 *
 * Sin formatear, sin HTML. Auth: JWT. Tenant por COMPANY_ID + roc. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportCustomersService.php';

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

$roc = getROC(1);
$svc = new ReportCustomersService();
apiOk(['rows' => $svc->ranking($from, $to, $roc, COMPANY_ID)]);
