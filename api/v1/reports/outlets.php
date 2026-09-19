<?php
/**
 * REST canónico (API compartida /api) — Reporte de Sucursales (raw).
 *
 *   GET /v1/reports/outlets?from=&to=&dataset=summary
 *       → { rows: [...], total: {...} }   tabla comparativa + fila total
 *   GET /v1/reports/outlets?from=&to=&dataset=series
 *       → { granularity, buckets, series: [{ outletId, name, points }] }
 *   GET /v1/reports/outlets?from=&to=&dataset=operations
 *       → { rows: [{ outletId, name, hours, payments, topItems }] }
 *
 * Read-only. Auth: realms `panel` y `api`. Permiso: `reports.sales.view`, el
 * mismo que el reporte de ventas y los KPIs del dashboard — son los mismos
 * números repartidos por sucursal (ver `OutletsComparisonService`).
 *
 * ── Alcance ─────────────────────────────────────────────────────────────────
 * Las sucursales ASIGNADAS al usuario (`OutletScope::current()`, `[]` = todo
 * el tenant), NO la sucursal única del selector del logo (`X-Outlet-Id`). El
 * reporte compara sucursales: acotarlo a la que está mirando lo dejaría con
 * una sola fila. El límite sí se respeta siempre: `current()` es exactamente
 * el conjunto contra el que `bootstrap.php` valida el selector, así que una
 * sucursal ajena no entra por ningún camino.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');

[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido (esperado Y-m-d o Y-m-d H:i:s)', 422);
}

$svc       = new \Punto\Api\Reports\OutletsComparisonService();
$companyId = (string) COMPANY_ID;
$outletIds = \Punto\Api\Outlets\OutletScope::current();
$dataset   = (string) (validateHttp('dataset') ?: 'summary');

try {
    switch ($dataset) {
        case 'summary':
            apiOk($svc->summary($from, $to, $companyId, $outletIds));
            break;
        case 'series':
            apiOk($svc->series($from, $to, $companyId, $outletIds));
            break;
        case 'operations':
            apiOk($svc->operations($from, $to, $companyId, $outletIds));
            break;
        default:
            apiError('dataset desconocido: ' . $dataset, 422);
    }
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}
