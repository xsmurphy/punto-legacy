<?php
/**
 * REST canónico — Dashboard del panel (motor ERP, raw).
 *
 *   GET /API/v1/reports/dashboard?widget=<nombre>&from=&to=[&week&prev&type]
 *       → datos CRUDOS del widget pedido.
 *
 * Read-only. Sin formatear: el front formatea + arma cada widget. Auth: JWT. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportDashboardService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$widgets = [
    'info', 'incomeOutcomeStats', 'paymentStatus', 'customers', 'customersRates',
    'topItems', 'topHours', 'topCategories', 'topBrands', 'topPayments', 'satisfaction',
    'orders', 'tables', 'schedule', 'notifications', 'notificationsCount', 'getReminders',
];
$widget = (string) (validateHttp('widget') ?: '');
if (!in_array($widget, $widgets, true)) {
    apiError('Widget no soportado', 422);
}

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

$opts = [
    'from' => $from,
    'to'   => $to,
    'week' => (bool) validateHttp('week'),
    'prev' => (bool) validateHttp('prev'),
    'type' => preg_replace('/[^a-zA-Z0-9_]/', '', (string) (validateHttp('type') ?: '')),
];

$svc = new ReportDashboardService();
apiOk($svc->widget($widget, $opts));
