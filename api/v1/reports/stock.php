<?php
/**
 * REST canónico (API compartida /api) — Niveles de Stock (multi-depósito) (raw).
 *
 *   GET /v1/reports/stock
 *       → { needsOutlet: bool, rows: [...] }. needsOutlet=true si no hay sucursal válida
 *         seleccionada. Filas crudas (números). Auth: realm `panel`. Ver REGLA RAÍZ 2.
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\StockService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

// Gate: requiere una sucursal válida (UUID) — el reporte agrupa stock por outlet.
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, (string) OUTLET_ID)) {
    apiOk(['needsOutlet' => true, 'rows' => []]);
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

apiOk(['needsOutlet' => false, 'rows' => $svc->levels($roc, COMPANY_ID, OUTLET_ID)]);
