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

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\CashflowService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
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
