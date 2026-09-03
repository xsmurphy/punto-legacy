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

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Flujo de efectivo del comercio, leído de `fin_movement` + `fin_account`. La clave es la misma que gobierna el módulo Finanzas del panel (`finance.manage`, ya exigida por `/v1/finance/*`): quien no puede entrar a Finanzas tampoco saca su flujo por acá.
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas: es la puerta ÚNICA que mide el permiso contra la PERSONA en los tres
 * realms (por qué, en el docblock de `api/lib/Auth/OperatorContext.php`). Acá
 * los realms son `panel` y `api`, donde las dos resuelven igual — usarla de
 * todos modos deja el gate correcto si mañana el endpoint acepta `pos-app`.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'finance.manage');

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));
if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

// Alcance del view-scope, igual que reports/stock.php y reports/dashboard.php.
// `[]` (el modo "Todas") consolida — no se filtra nada.
//
// NO se usa `Roc::build()`: el service arma sus propias queries y su filtro de
// sucursal sale de `OutletScope::sqlFilter()`, que además tiene que sumar las
// cuentas GLOBALES (`outletid IS NULL`) — algo que `Roc` no expresa.
// `OutletScope::effectiveIds()` reemplaza al idiom que estaba copiado acá y en
// otros cuatro endpoints (leer VIEW_OUTLET_ID, caer a OUTLET_ID, validar el
// uuid), y a diferencia de `single()` sabe expresar el subconjunto de 2+
// sucursales: el flujo de efectivo suma movimientos, así que acotarlo al
// conjunto del usuario da el número correcto en vez de un 422.
$effectiveOutletIds = \Punto\Api\Outlets\OutletScope::effectiveIds();

apiOk($svc->getCashFlow($from, $to, (string) COMPANY_ID, $effectiveOutletIds));
