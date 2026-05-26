<?php
/**
 * REST canónico — Reportes de Ventas (motor ERP, raw).
 *
 *   GET /API/v1/reports/sales?from=<datetime>&to=<datetime>&dataset=<tipo>
 *
 *   dataset (default 'summary'):
 *     summary → totales, devoluciones, por tipo, giftcards, medios, non-adding.
 *     series  → series por fecha/hora de UN período (ventas tipos 0,3,6 + egresos 1,4),
 *               para el gráfico. El BFF llama por período (actual + anterior) y compone.
 *     hours   → conteo de ventas por hora del día (gráfico "Ventas por Hora").
 *     byday   → filas por día (pestaña "Por Día").
 *
 * Todo CRUDO: sin formatear, sin HTML. La composición (netSales, comparaciones,
 * margin, byweek, anotaciones) y el formateo viven en el BFF/front. La API no conoce
 * a la App Punto — es reusable por otras apps sobre el mismo motor.
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

$roc     = getROC(1);
$dataset = (string) (validateHttp('dataset') ?: 'summary');
$svc     = new ReportSalesService();

switch ($dataset) {
    case 'series':
        // El rango es de un solo día si ambas fechas caen en la misma fecha calendario.
        $isDay = (substr($from, 0, 10) === substr($to, 0, 10));
        apiOk($svc->series($from, $to, $roc, $isDay));
        break;

    case 'hours':
        apiOk($svc->hours($from, $to, $roc));
        break;

    case 'byday':
        apiOk($svc->byDay($from, $to, $roc));
        break;

    case 'summary':
        apiOk($svc->summary($from, $to, $roc, COMPANY_ID));
        break;

    default:
        apiError('dataset desconocido: ' . $dataset, 422);
}
