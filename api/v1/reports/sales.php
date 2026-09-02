<?php
/**
 * REST canónico (API compartida /api) — Reportes de Ventas (raw).
 *
 *   GET /v1/reports/sales?from=&to=&dataset=<tipo>
 *
 *   dataset (default 'summary'):
 *     summary → totales, devoluciones, por tipo, giftcards, medios, non-adding.
 *     series  → series por fecha/hora de UN período (BFF llama actual + anterior).
 *     hours   → conteo de ventas por hora del día.
 *     byday   → filas por día.
 *
 * Auth: realms `panel` y `api` (lectura programatica: API keys / MCP). Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\SalesService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Es EL reporte de ventas del comercio: totales, series, devoluciones y medios de pago de todas las cajas.
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas: es la puerta ÚNICA que mide el permiso contra la PERSONA en los tres
 * realms (por qué, en el docblock de `api/lib/Auth/OperatorContext.php`). Acá
 * los realms son `panel` y `api`, donde las dos resuelven igual — usarla de
 * todos modos deja el gate correcto si mañana el endpoint acepta `pos-app`.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));

if (!$rangeOk) {
    apiError('Formato de fecha inválido (esperado Y-m-d o Y-m-d H:i:s)', 422);
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

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$dataset = (string) (validateHttp('dataset') ?: 'summary');

switch ($dataset) {
    case 'series':
        $isDay = (substr($from, 0, 10) === substr($to, 0, 10));
        apiOk($svc->series($from, $to, $roc, $isDay, $hours));
        break;

    case 'hours':
        apiOk($svc->hours($from, $to, $roc, $hours));
        break;

    case 'byday':
        apiOk($svc->byDay($from, $to, $roc, $hours));
        break;

    case 'summary':
        apiOk($svc->summary($from, $to, $roc, (string) COMPANY_ID, $hours));
        break;

    default:
        apiError('dataset desconocido: ' . $dataset, 422);
}
