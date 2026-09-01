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

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));

if (!$rangeOk) {
    apiError('Formato de fecha inválido (esperado Y-m-d o Y-m-d H:i:s)', 422);
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
        apiOk($svc->series($from, $to, $roc, $isDay));
        break;

    case 'hours':
        apiOk($svc->hours($from, $to, $roc));
        break;

    case 'byday':
        apiOk($svc->byDay($from, $to, $roc));
        break;

    case 'summary':
        apiOk($svc->summary($from, $to, $roc, (string) COMPANY_ID));
        break;

    default:
        apiError('dataset desconocido: ' . $dataset, 422);
}
