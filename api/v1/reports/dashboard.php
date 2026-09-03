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

/* ───────── Gate de LECTURA — por WIDGET, no por endpoint ───────────────────
 *
 * Este archivo no sirve un reporte: sirve dieciocho cosas distintas detrás del
 * mismo `?widget=`. Una sola clave para todas sería la equivocada casi siempre
 * — y la de arriba (`reports.sales.view`) dejaría a un cajero con la pantalla
 * de Inicio del panel rota, porque el home pide ocho widgets de una
 * (`frontend/app/(panel)/page.tsx:91-99`) y varios no tienen nada que ver con
 * ventas.
 *
 * El corte es por lo que cada widget DEVUELVE:
 *
 *   Con clave — plata del comercio. Facturación del período, ticket promedio,
 *   estado de cobranza, ranking de artículos/categorías/marcas/medios de pago,
 *   horas pico y analítica de clientes. Es exactamente lo que el panel muestra
 *   detrás de los reportes de ventas: un rol que no puede abrir esos reportes
 *   tampoco los saca por el dashboard ni preguntándole al asistente
 *   (`get_sales_kpis` y `get_customer_evolution` pegan acá).
 *   `satisfaction` y `schedule` van con SU clave, no con la de ventas: son el
 *   mismo dato que `reports/satisfaction.php` y `reports/schedule.php`, que ya
 *   las exigen. Colgarlos de ventas pediría el permiso equivocado.
 *
 *   Sin clave, a propósito — el ARMAZÓN operativo del home, sin un monto en
 *   ningún campo: `info` (contadores del plan, sucursales, cajas abiertas),
 *   `orders` y `tables` (órdenes activas y ocupación del salón, que es lo que
 *   mira quien atiende), `notifications`/`notificationsCount` y `getReminders`
 *   (avisos del usuario, ni siquiera del comercio; `getReminders` devuelve []).
 *   Dejarlos abiertos no es un olvido: gatearlos vaciaría la pantalla de Inicio
 *   de la gente que la usa para trabajar, sin esconder ninguna cifra.
 *
 * Va por `OperatorContext::requirePermission()` por el mismo motivo que el
 * resto del directorio (ver el docblock de `api/lib/Auth/OperatorContext.php`).
 */
const WIDGET_PERMISO = [
    'incomeOutcomeStats' => 'reports.sales.view',
    'paymentStatus'      => 'reports.sales.view',
    'customers'          => 'reports.sales.view',
    'customersRates'     => 'reports.sales.view',
    'customersSeries'    => 'reports.sales.view',
    'topItems'           => 'reports.sales.view',
    'topHours'           => 'reports.sales.view',
    'topCategories'      => 'reports.sales.view',
    'topBrands'          => 'reports.sales.view',
    'topPayments'        => 'reports.sales.view',
    'satisfaction'       => 'reports.satisfaction.view',
    'schedule'           => 'reports.schedule.view',
];
if (isset(WIDGET_PERMISO[$widget])) {
    require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
    \Punto\Api\Auth\OperatorContext::requirePermission($ctx, WIDGET_PERMISO[$widget]);
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
// `OutletScope::single()` unifica el idiom (VIEW_OUTLET_ID → OUTLET_ID → guard
// de uuid). Devuelve `null` cuando el alcance es un subconjunto de 2+
// sucursales: el 5º argumento de `widget()` es un valor único y los widgets que
// lo usan no pasan por `$roc`, así que ahí no hay forma de expresar el conjunto
// — se corta en vez de servir una sucursal disfrazada de todas.
$effectiveOutletId = \Punto\Api\Outlets\OutletScope::single();
if ($effectiveOutletId === null) {
    apiError(\Punto\Api\Outlets\OutletScope::subsetNotSupportedMessage(), 422);
}

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
