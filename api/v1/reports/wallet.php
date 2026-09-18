<?php
/**
 * REST canónico (API compartida /api) — Reporte de Bolsillos (wallet,
 * context/74 §13).
 *
 *   GET /v1/reports/wallet?from=&to=&dataset=<summary|full>
 *
 *   dataset (default 'full'):
 *     summary → KPIs: cargado, consumido, saldo vigente, diferencias.
 *               El panel lo pide también para el período anterior.
 *     full    → summary + byDay + byProduct + byPocket + differences
 *               (detalle y agrupado por usuario y por caja).
 *
 * Es la pestaña "Bolsillos" del reporte de Ventas, y el control de los
 * consumos con saldo: ver el docblock de `WalletReportService`.
 *
 * Auth: realms `panel` y `api`, mismo par que `reports/sales.php`. Gate
 * `reports.sales.view` (la pestaña vive dentro de Ventas: quien ve Ventas ve
 * Bolsillos) + módulo `wallet` activo, verificado acá y no solo en la UI.
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');

if (!(new \Punto\Api\Modules\ModulesService())->isEnabled((string) COMPANY_ID, 'wallet')) {
    apiError('El saldo de clientes no está activo en este comercio', 403);
}

[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido (esperado Y-m-d o Y-m-d H:i:s)', 422);
}

// Alcance de sucursal (context/25): `Roc::build` respeta VIEW_OUTLET_ID /
// VIEW_OUTLET_IDS, ya validados contra las sucursales del usuario. Alias `t`:
// el service lo aplica sobre `transaction` (cargas y consumos).
try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID, 't');
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$svc     = new \Punto\Api\Reports\WalletReportService();
$dataset = (string) (validateHttp('dataset') ?: 'full');

switch ($dataset) {
    case 'summary':
        apiOk($svc->summary($from, $to, $roc, (string) COMPANY_ID));
        break;

    case 'full':
        apiOk($svc->full($from, $to, $roc, (string) COMPANY_ID));
        break;

    default:
        apiError('dataset desconocido: ' . $dataset, 422);
}
