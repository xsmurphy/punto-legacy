<?php
/**
 * REST canónico — Reporte de Producción (motor ERP, raw).
 *
 *   GET /API/v1/reports/production?view=general|detail|compound&from=&to=[&byDay=1]
 *       → datos CRUDOS según la vista.
 *
 * SOLO las vistas de LECTURA. El modal de receta (`recipe`), el export y el write (`delete`)
 * siguen sirviéndose por el PHP legacy vía ?action=. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportProductionService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

$view = (string) (validateHttp('view') ?: 'general');
if (!in_array($view, ['general', 'detail', 'compound'], true)) {
    apiError('Vista no soportada', 422);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

$svc = new ReportProductionService();

if ($view === 'detail') {
    apiOk($svc->detail($from, $to));
} elseif ($view === 'compound') {
    apiOk($svc->compound($from, $to, (bool) validateHttp('byDay')));
} else {
    apiOk($svc->general($from, $to));
}
