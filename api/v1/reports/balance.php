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

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Foto de Activo / Pasivo / Patrimonio del comercio. Misma clave que el módulo Finanzas por el mismo motivo que `cashflow.php`.
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas: es la puerta ÚNICA que mide el permiso contra la PERSONA en los tres
 * realms (por qué, en el docblock de `api/lib/Auth/OperatorContext.php`). Acá
 * los realms son `panel` y `api`, donde las dos resuelven igual — usarla de
 * todos modos deja el gate correcto si mañana el endpoint acepta `pos-app`.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'finance.manage');

// Alcance del view-scope, mismo patrón que reports/stock.php. `[]` = todas.
// `OutletScope::effectiveIds()` unifica el idiom (VIEW_OUTLET_ID → OUTLET_ID →
// conjunto asignado) y devuelve los tres estados sin perder ninguno: antes un
// alcance de 2+ sucursales cortaba con 422 y el dueño de dos locales no tenía
// balance. Todos los rubros de este reporte SUMAN (efectivo, cobrar, pagar,
// inventario), así que el consolidado acotado es exactamente lo que pidió.
$effectiveOutletIds = \Punto\Api\Outlets\OutletScope::effectiveIds();

apiOk((new \Punto\Api\Reports\BalanceService())->get((string) COMPANY_ID, $effectiveOutletIds));
