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

// Outlet efectivo: si el browser eligió scope (VIEW_OUTLET_ID definida vía
// header X-Outlet-Id), prevalece sobre OUTLET_ID del JWT. Tanto el gate como
// el service necesitan ver el efectivo, no el raw — si no, el agregado del
// ledger (SUM por sucursal) sale de la sucursal equivocada en modo "Todas" o
// al switchear.
$effectiveOutletId = defined('VIEW_OUTLET_ID') ? (string) constant('VIEW_OUTLET_ID') : (string) OUTLET_ID;

// Gate: requiere una sucursal válida (UUID) — el reporte agrupa stock por outlet.
// Modo "Todas" (VIEW_OUTLET_ID='') NO permite este reporte (no tiene sentido
// stock por sucursal sin una sucursal).
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, $effectiveOutletId)) {
    apiOk(['needsOutlet' => true, 'rows' => []]);
}

// El service ya no recibe fragmentos SQL interpolados (context/52): el scope
// viaja bindeado. Lo que hacía falta conservar de `Roc::build` es su guard de
// contexto — que COMPANY_ID sea un UUID real antes de consultar.
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido (companyId no es UUID)', 500);
}

apiOk(['needsOutlet' => false, 'rows' => $svc->levels(COMPANY_ID, $effectiveOutletId)]);
