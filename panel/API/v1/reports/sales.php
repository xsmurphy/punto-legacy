<?php
/**
 * REST canónico — Reportes de Ventas (motor ERP, raw).
 *
 *   GET /API/v1/reports/sales?from=<datetime>&to=<datetime>
 *       → dataset crudo del resumen de ventas de un período (totales, devoluciones,
 *         por tipo, giftcards, medios de pago, non-adding-to-sales). SIN formatear.
 *
 * El BFF (panel/bff/reports/summary.php) llama esto una vez por período (actual +
 * anterior), compone netSales, formatea y arma las comparaciones. La API no formatea
 * ni conoce a la App Punto — es reusable por otras apps sobre el mismo motor.
 *
 * Auth: JWT (cookie _jwt_panel / Bearer / POST _jwt). Tenant por COMPANY_ID del JWT.
 * Respuesta: envelope canónico { ok, data, meta } / { ok:false, error }.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportSalesService.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    apiError('Método no permitido', 405);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');

// Defaults: últimos 7 días si no se especifica.
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

// Validación de formato (los valores van como params, pero rechazamos basura temprano).
$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido (esperado Y-m-d o Y-m-d H:i:s)', 422);
}

$roc = getROC(1);

$svc = new ReportSalesService();
apiOk($svc->summary($from, $to, $roc, COMPANY_ID));
