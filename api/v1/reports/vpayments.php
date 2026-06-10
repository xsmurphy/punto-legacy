<?php
/**
 * REST canónico (API compartida /api) — Pagos ePOS / vPayments (motor ERP, raw — gateway externo).
 *
 *   GET /v1/reports/vpayments?from=&to=   → registros de pagos ePOS + totales, CRUDO.
 *
 * La capa API es la única que habla con el servicio externo (proxy a get_vpayments → Bancard/Dinelco).
 * Read-only. Sin formatear: el front formatea + arma tabla/donut. Auth: realm `panel`. Ver REGLA RAÍZ 2.
 *
 * Port FIEL de panel/API/v1/reports/vpayments.php (Fase 2 del desacople de /panel — último
 * reporte). Cambios: `apiMiddleware()` → `apiAuthTenant(['panel'])`; service en namespace
 * `Punto\Api\Reports\VPaymentsService` (cae el prefijo `Report`). companyId del contexto.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
$from = (string) (validateHttp('from') ?: '');
$to   = (string) (validateHttp('to')   ?: '');
if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }
if (!preg_match($dateRe, $from) || !preg_match($dateRe, $to)) {
    apiError('Formato de fecha inválido', 422);
}

$svc = new \Punto\Api\Reports\VPaymentsService();
apiOk($svc->general($from, $to, COMPANY_ID));
