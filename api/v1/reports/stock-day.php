<?php
/**
 * REST canónico (API compartida /api) — Niveles de Stock por Día (raw).
 *
 *   GET /v1/reports/stock-day?date=<datetime>
 *       → filas crudas [{itemId, name, sku, cogs, onHand}] de los ítems que rastrean inventario.
 *
 * Sin formatear, sin HTML. Auth: realm `panel`. Tenant por COMPANY_ID + ROC sin prefijo.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\StockDayService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$date = (string) (validateHttp('date') ?: '');
if ($date === '') { $date = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($dateRe, $date)) {
    apiError('Formato de fecha inválido', 422);
}
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

// Roc::build sin prefijo (el service consulta directo a `stock`). Respeta
// VIEW_OUTLET_ID si el browser mandó X-Outlet-Id (panel-next 2026-06-13).
try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk($svc->levels($date, COMPANY_ID, $roc));
