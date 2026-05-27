<?php
/**
 * REST canónico — Reporte de Ventas por Usuarios / Recursos (motor ERP, raw).
 *
 *   GET /API/v1/reports/users?from=<datetime>&to=<datetime>
 *       → filas crudas por usuario [{userId, name, usold, total, comission, discount, count}].
 *
 * Sin formatear, sin HTML. El BFF suma totales; el front formatea + arma tabla/KPIs/chart.
 * Auth: JWT. Tenant por COMPANY_ID. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportUsersService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

$svc = new ReportUsersService();
apiOk($svc->salesByUser($from, $to, COMPANY_ID));
