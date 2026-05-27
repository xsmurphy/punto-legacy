<?php
/**
 * REST canónico — Niveles de Stock (multi-depósito) (motor ERP, raw).
 *
 *   GET /API/v1/reports/stock
 *       → { needsOutlet: bool, rows: [...] }. needsOutlet=true si no hay sucursal válida
 *         seleccionada (reemplaza el gate OUTLET_ID<2 del legacy, roto en PG). Filas crudas.
 *
 * Sin formatear, sin HTML. Auth: JWT. Tenant por COMPANY_ID + roc. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportStockService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

// Gate: requiere una sucursal válida (UUID). Reemplaza `OUTLET_ID < 2` del legacy.
$isUuid = is_string(OUTLET_ID) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', OUTLET_ID);
if (!$isUuid) {
    apiOk(['needsOutlet' => true, 'rows' => []]);
}

$roc = getROC(1);
$svc = new ReportStockService();
apiOk(['needsOutlet' => false, 'rows' => $svc->levels($roc, COMPANY_ID, OUTLET_ID)]);
