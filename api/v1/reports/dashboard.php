<?php
/**
 * REST canónico (API compartida /api) — Dashboard del panel (raw).
 *
 *   GET /v1/reports/dashboard?widget=<nombre>&from=&to=[&week&prev&type]
 *       → datos CRUDOS del widget pedido.
 *
 * Read-only. Sin formatear: el front formatea + arma cada widget. Auth: realm `panel`.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\DashboardService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$widgets = [
    'info', 'incomeOutcomeStats', 'paymentStatus', 'customers', 'customersRates', 'customersSeries',
    'topItems', 'topHours', 'topCategories', 'topBrands', 'topPayments', 'satisfaction',
    'orders', 'tables', 'schedule', 'notifications', 'notificationsCount', 'getReminders',
];
$widget = (string) (validateHttp('widget') ?: '');
if (!in_array($widget, $widgets, true)) {
    apiError('Widget no soportado', 422);
}

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

$opts = [
    'from' => $from,
    'to'   => $to,
    'week' => (bool) validateHttp('week'),
    'prev' => (bool) validateHttp('prev'),
    'type' => preg_replace('/[^a-zA-Z0-9_]/', '', (string) (validateHttp('type') ?: '')),
];

// Outlet efectivo (mismo patrón que stock.php): VIEW_OUTLET_ID si el browser
// eligió scope vía X-Outlet-Id, OUTLET_ID del JWT en su defecto. DashboardService
// usa el 5to argumento para widgets que NO pasan por $roc (`schedule` query
// directa + envío a notifyGateway), así que tienen que ver el efectivo.
$effectiveOutletId = defined('VIEW_OUTLET_ID') ? (string) constant('VIEW_OUTLET_ID') : (string) OUTLET_ID;

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, $effectiveOutletId);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk($svc->widget(
    $widget,
    $opts,
    $roc,
    (string) COMPANY_ID,
    $effectiveOutletId,
    (string) $ctx['userId']
));
