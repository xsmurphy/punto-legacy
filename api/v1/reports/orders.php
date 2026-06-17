<?php
/**
 * REST canónico (API compartida /api) — Reporte de Órdenes (motor ERP, raw).
 *
 *   GET /v1/reports/orders?from=&to=  → { rows } CRUDO (transaction type=12).
 *
 * Lectura sin formatear (el front mapea estado→badge y formatea fecha/monto).
 * Auth: realm `panel` (apiAuthTenant(['panel'])). Tenant por COMPANY_ID + outlet (ROC).
 * Ver REGLA RAÍZ 2. Mismo patrón que los demás reportes de la /api compartida.
 */

require_once __DIR__ . '/../../bootstrap.php';

apiAuthTenant(['panel']);

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

// Roc::build respeta VIEW_OUTLET_ID si el browser mandó X-Outlet-Id (dropdown del logo).
try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$customerId = trim((string) ($_GET['customerId'] ?? '')) ?: null;

$svc = new \Punto\Api\Reports\OrdersService();
apiOk(['rows' => $svc->listOrders($from, $to, $roc, COMPANY_ID, $customerId)]);
