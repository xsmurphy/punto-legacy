<?php
/**
 * REST canónico (API compartida /api) — Reporte de Producción (raw).
 *
 *   GET /v1/reports/production?view=general|detail|compound&from=&to=[&byDay=1]
 *
 * SOLO las vistas de LECTURA. El modal de receta (`recipe`), export y write (`delete`)
 * siguen sirviéndose por el PHP legacy vía ?action= (migración parcial).
 * Auth: realms `panel` y `api` (lectura programatica: API keys / MCP). Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\ProductionService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$view = (string) (validateHttp('view') ?: 'general');
if (!in_array($view, ['general', 'detail', 'compound', 'waste'], true)) {
    apiError('Vista no soportada', 422);
}

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$companyId = (string) COMPANY_ID;

if ($view === 'detail') {
    apiOk($svc->detail($from, $to, $roc, $companyId));
} elseif ($view === 'compound') {
    apiOk($svc->compound($from, $to, $roc, $companyId, (bool) validateHttp('byDay')));
} elseif ($view === 'waste') {
    apiOk($svc->waste($from, $to, $roc, $companyId));
} else {
    apiOk($svc->general($from, $to, $roc, $companyId));
}
