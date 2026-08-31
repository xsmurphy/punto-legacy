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

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\ProductionService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

$view = (string) (validateHttp('view') ?: 'general');
if (!in_array($view, ['general', 'detail', 'compound', 'waste'], true)) {
    apiError('Vista no soportada', 422);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
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
