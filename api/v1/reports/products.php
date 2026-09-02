<?php
/**
 * REST canónico (API compartida /api) — Reporte de Artículos / Productos (raw).
 *
 *   GET /v1/reports/products?view=general|detail|combos&from=&to=
 *       [&cusId=&usrId=&itmId=&month=&year=&src=]
 *
 * Read-only. Sin formatear/HTML: el BFF calcula utilidad/KPIs/chart, el front formatea.
 * Auth: realm `panel`. Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\ProductsService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Ventas por artículo, con costo y utilidad. Es el reporte de ventas abierto por producto, no el catálogo (para el catálogo está `inventory.item.view` en items.php).
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas: es la puerta ÚNICA que mide el permiso contra la PERSONA en los tres
 * realms (por qué, en el docblock de `api/lib/Auth/OperatorContext.php`). Acá
 * los realms son `panel` y `api`, donde las dos resuelven igual — usarla de
 * todos modos deja el gate correcto si mañana el endpoint acepta `pos-app`.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

$view = (string) (validateHttp('view') ?: 'general');
if (!in_array($view, ['general', 'detail', 'combos'], true)) {
    apiError('Vista no soportada', 422);
}

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

// Franja horaria del reporte (F1 de context/67). Es una dimensión APARTE del
// rango: el rango es un intervalo CONTINUO, así que "del 1 al 30 de 07:00 a
// 11:59" mandado como from/to incluye las noches del medio. `hourFrom`/`hourTo`
// se repiten en cada día del rango. Sin ellos la banda es vacía y la query sale
// byte por byte como salía antes de esta feature.
[$hours, $hoursOk] = \Punto\Api\Reports\HourBand::fromRequest(validateHttp('hourFrom'), validateHttp('hourTo'));
if (!$hoursOk) {
    apiError('Formato de franja horaria inválido (esperado HH:MM o HH:MM:SS)', 422);
}

$uuidOrEmpty = function ($v) use ($uuidRe) {
    $v = (string) ($v ?: '');
    return ($v !== '' && preg_match($uuidRe, $v)) ? $v : '';
};

$filters = [
    'cusId' => $uuidOrEmpty(validateHttp('cusId')),
    'usrId' => $uuidOrEmpty(validateHttp('usrId')),
    'itmId' => $uuidOrEmpty(validateHttp('itmId')),
    'month' => (bool) validateHttp('month'),
    'year'  => (int) (validateHttp('year') ?: 0),
    'src'   => trim((string) (validateHttp('src') ?: '')),
];

// Mismo criterio que en transactions.php: los filtros por cliente y por
// artículo devuelven el historial completo del ítem/cliente sin acotar por
// fecha, así que la franja no puede acompañar a un rango que esas ramas no
// aplican. Se rechaza explícito en vez de filtrar de más (plan degradado) o de
// menos (resultado que parece filtrado y no lo está). `usrId` y `src` SÍ acotan
// por rango, así que con esos la franja es válida.
if (!$hours->isEmpty() && ($filters['cusId'] !== '' || $filters['itmId'] !== '')) {
    apiError('La franja horaria no se puede combinar con el filtro por cliente o por artículo: esas vistas devuelven el historial completo, sin acotar por fecha.', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'detail') {
    apiOk($svc->detail($filters, $from, $to, $roc, $companyId, $hours));
} elseif ($view === 'combos') {
    apiOk($svc->combos($filters, $from, $to, $roc, $companyId, $hours));
} else {
    apiOk($svc->general($filters, $from, $to, $roc, $companyId, $hours));
}
