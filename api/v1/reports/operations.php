<?php
/**
 * REST canónico (API compartida /api) — KPIs de OPERACIÓN de órdenes y espacios.
 *
 *   GET /v1/reports/operations?from=&to=&include=volume,stages,demand,spaces
 *       → { volume, stages, demand, spaces } CRUDO. `include` recorta los
 *         bloques; ausente = todos.
 *
 * Vocabulario GENÉRICO a propósito (regla del owner 2026-09-10): el módulo de
 * órdenes lo usa un taller igual que un restaurante, así que acá hay etapas de
 * proceso y espacios, no cocina ni mesas. Ver el docblock de OperationsService.
 *
 * CADA bloque devuelve su propia `coverage`. No es un extra: los tiempos entre
 * etapas existen solo si alguien marca los estados mientras trabaja, y la
 * máquina de estados permite saltearlos. Un promedio sobre el 20% de las
 * órdenes no se puede interpretar sin saber que es el 20%.
 *
 * El BFF formatea, el front arma los charts. Auth: realms `panel` y `api`.
 * Tenant por COMPANY_ID + outlet (ROC sin prefix). Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * `reports.sales.view`, la MISMA clave que `reports/orders.php`, y no una
 * nueva: este reporte responde sobre las mismas órdenes que aquel ya lista,
 * con otro corte. Una clave propia dejaría a un rol viendo el listado y no el
 * resumen del listado, que es una distinción que nadie pidió y que hay que
 * mantener para siempre.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');

[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Rango de fechas inválido', 422);
}

// Bloques pedidos. Se filtra contra una lista blanca en vez de pasar el input
// al service: `include` viene del cliente y termina eligiendo qué queries
// corren.
$allowed = ['volume', 'stages', 'demand', 'spaces'];
$include = array_values(array_intersect(
    array_map('trim', explode(',', (string) (validateHttp('include') ?? ''))),
    $allowed
));

// `Roc::build` respeta VIEW_OUTLET_ID si el browser mandó X-Outlet-Id (el
// selector de sucursal del logo), igual que `reports/orders.php`.
try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$svc = new \Punto\Api\Reports\OperationsService();
apiOk($svc->report($from, $to, $roc, COMPANY_ID, $include));
