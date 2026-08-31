<?php
/**
 * REST canónico (API compartida /api) — Reporte de Flujo de Caja (raw).
 *
 *   GET /v1/reports/cashflow?from=&to=  → flujo de efectivo del período, CRUDO.
 *
 * Fuente: `fin_movement` + `fin_account` (B1 de context/60). El reporte cuadra
 * por construcción: saldo inicial + entradas − salidas = saldo final, y expone
 * `balances.check` con la diferencia para que un desvío se vea solo.
 *
 * Read-only. Sin formatear. Auth: realm `panel` + `api` (lectura programática).
 */

require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\CashflowService();

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

// Sucursal del view-scope, igual que reports/stock.php y reports/dashboard.php.
// '' (el modo "Todas") consolida — no se filtra nada.
//
// NO se usa `Roc::build()`: ese helper interpola el fragmento SQL y el service
// nuevo bindea sus parámetros. El outletId va como valor, no como texto de
// query.
$effectiveOutletId = defined('VIEW_OUTLET_ID') ? (string) constant('VIEW_OUTLET_ID') : (string) OUTLET_ID;
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, $effectiveOutletId)) {
    $effectiveOutletId = '';
}

apiOk($svc->getCashFlow($from, $to, (string) COMPANY_ID, $effectiveOutletId));
