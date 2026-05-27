<?php
/**
 * REST canónico — Reporte de Ventas por Medios de Pago (motor ERP, raw).
 *
 *   GET /API/v1/reports/payment-methods?from=<datetime>&to=<datetime>
 *       → { detail: [...], summary: [...] } CRUDO (números sin formatear, sin HTML).
 *         detail = una fila por medio de pago de cada transacción (datos ya resueltos:
 *         cliente, sucursal, prefijo de factura, nombre del medio). summary = agrupado
 *         por medio, ordenado por monto desc.
 *
 * El BFF (panel/bff/reports/payment-methods.php) pre-formatea los montos; el front arma
 * las tablas + el chart. La API no formatea ni emite markup. Ver context/02-arquitectura.md.
 *
 * Auth: JWT (cookie _jwt_panel / Bearer / POST _jwt). Tenant por COMPANY_ID del JWT.
 * Respuesta: envelope canónico { ok, data, meta } / { ok:false, error }.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportPaymentMethodsService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido (esperado Y-m-d o Y-m-d H:i:s)', 422);
}

$roc = getROC(1);
$svc = new ReportPaymentMethodsService();
apiOk($svc->report($from, $to, $roc, COMPANY_ID));
