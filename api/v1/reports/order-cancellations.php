<?php
/**
 * REST canónico (API compartida /api) — Anulaciones de comanda.
 *
 *   GET /v1/reports/order-cancellations?from=&to=&outletId=
 *       → { rows: [...], totals: { count, amount } }
 *
 * Qué contesta: qué se borró de una comanda —una línea suelta o la orden
 * ENTERA—, cuándo, por qué motivo, cuánta plata representaba y QUIÉN lo hizo.
 * Es el reporte que le da sentido al motivo obligatorio y al arreglo de
 * atribución de `OrderCoreService::recordEvent()` — sin esta pantalla, el dato
 * quedaría guardado y nadie lo miraría.
 *
 * Cubría solo el grano ÍTEM hasta 2026-09-06 (`order-item-cancellations`, el
 * service filtraba `scope='item'`): una orden de ocho líneas cancelada entera
 * no aparecía en ninguna fila, que era el caso más grave y el único invisible.
 * La ruta se renombró junto con el alcance — la vieja NO se mantiene como
 * alias: el único consumidor es la pantalla del panel, que viaja en el mismo
 * deploy, y un alias sin dueño es una ruta que nadie vuelve a mirar.
 *
 * Auth: realms `panel` y `api` (lectura programática: API keys / MCP). Mismo
 * par que los demás reportes de esta carpeta. NO acepta `pos-app`: la caja no
 * consulta reportes históricos, y abrir el realm es una decisión aparte (ver el
 * docblock de `reports/drawers.php`).
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * `reports.audit.view` — y no una clave nueva, ni `reports.sales.view`.
 *
 * Por qué `audit` y no `sales`: lo que expone este reporte no son ventas. Son
 * ACCIONES DE PERSONAS con su motivo escrito y el nombre de quien las hizo —
 * exactamente la misma naturaleza que la auditoría del tenant, que es lo que
 * `reports.audit.view` gobierna hoy. Un rol que puede ver el reporte de ventas
 * no debería por eso ver qué borró cada empleado y qué excusa puso.
 *
 * Por qué NO una clave nueva (`reports.orderCancellations.view`): una clave
 * recién nacida no la tiene NADIE hasta que un admin la tilda tenant por
 * tenant. El reporte quedaría invisible el día del deploy, incluido para el
 * encargado que pidió la feature. Con `reports.audit.view` los dos roles que
 * corresponden —`owner` y `manager`— ya la tienen en el seed, y `cashier` no,
 * que es la separación correcta: el cajero anula, el encargado audita.
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas por lo mismo que `reports/orders.php`: es la puerta única que mide el
 * permiso contra la PERSONA en los tres realms.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.audit.view');

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese día (ver
// Date::reportRange) — mandar `to=2026-09-01` y perder todo lo de ese día
// después de medianoche era el bug que reportó el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

// Alcance de sucursal (context/25): `Roc::build` respeta `VIEW_OUTLET_ID` —el
// que definió `bootstrap.php` a partir del header `X-Outlet-Id` (panel) o del
// `?outletId=` de la query (realm `api`), YA validado contra las sucursales
// asignadas al usuario— y cae al consolidado de `VIEW_OUTLET_IDS` cuando no se
// pidió ninguna. O sea que `?outletId=` del contrato NO se lee acá: leerlo de
// nuevo sería saltearse esa validación. El alias `e` es el de
// `pos_order_event` en la query del service.
try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID, 'e');
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$svc = new \Punto\Api\Reports\OrderCancellationsService();
apiOk($svc->report($from, $to, $roc));
