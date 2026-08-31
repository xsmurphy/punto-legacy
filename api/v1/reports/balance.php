<?php
/**
 * REST canónico (API compartida /api) — Balance GERENCIAL (raw).
 *
 *   GET /v1/reports/balance → foto de Activo / Pasivo / Patrimonio neto, CRUDO.
 *
 * B3 de `context/60`. NO es un balance contable: no hay plan de cuentas ni
 * asientos, y el patrimonio es DERIVADO (Activo − Pasivo). Decisión del owner
 * 2026-08-31: Punto no se mete en lo contable.
 *
 * Sin parámetros de fecha A PROPÓSITO: un balance es una FOTO, y en esta versión
 * es a HOY. El resto de los reportes del panel son por rango; éste no, y la UI
 * tiene que reflejarlo.
 *
 * Read-only. Auth: realm `panel` + `api` (lectura programática).
 */

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/Reports/BalanceService.php';

$ctx = apiAuthTenant(['panel', 'api']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

// Sucursal del view-scope, mismo patrón que reports/stock.php. '' = todas.
$effectiveOutletId = defined('VIEW_OUTLET_ID') ? (string) constant('VIEW_OUTLET_ID') : (string) OUTLET_ID;
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, $effectiveOutletId)) {
    $effectiveOutletId = '';
}

apiOk((new \Punto\Api\Reports\BalanceService())->get((string) COMPANY_ID, $effectiveOutletId));
