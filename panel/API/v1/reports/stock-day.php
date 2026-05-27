<?php
/**
 * REST canónico — Niveles de Stock por Día (motor ERP, raw).
 *
 *   GET /API/v1/reports/stock-day?date=<datetime>
 *       → filas crudas [{itemId, name, sku, cogs, onHand}] de los ítems que rastrean
 *         inventario, con su existencia/valor según el último movimiento hasta `date`.
 *
 * Sin formatear, sin HTML. Auth: JWT. Tenant por COMPANY_ID + roc. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportStockDayService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$date = (string) (validateHttp('date') ?: '');
if ($date === '') { $date = date('Y-m-d 23:59:59'); }

$dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
if (!preg_match($dateRe, $date)) {
    apiError('Formato de fecha inválido', 422);
}

$roc = getROC(1);
$svc = new ReportStockDayService();
apiOk($svc->levels($date, COMPANY_ID, $roc));
