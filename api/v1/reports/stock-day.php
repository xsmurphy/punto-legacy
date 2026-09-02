<?php
/**
 * REST canónico (API compartida /api) — Niveles de Stock por Día (raw).
 *
 *   GET /v1/reports/stock-day?date=<datetime>
 *       → filas crudas [{itemId, name, sku, cogs, onHand}] de los ítems que rastrean inventario.
 *
 * Sin formatear, sin HTML. Auth: realm `panel`. Tenant por COMPANY_ID + ROC sin prefijo.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel']);
$svc = new \Punto\Api\Reports\StockDayService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Saldo de stock al cierre de un día. Misma clave que el catálogo, igual que `inventory.php`.
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas: es la puerta ÚNICA que mide el permiso contra la PERSONA en los tres
 * realms (por qué, en el docblock de `api/lib/Auth/OperatorContext.php`). Acá
 * los realms son `panel` y `api`, donde las dos resuelven igual — usarla de
 * todos modos deja el gate correcto si mañana el endpoint acepta `pos-app`.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'inventory.item.view');

// Saldo AL CIERRE del día pedido: `date` es un extremo SUPERIOR, así que una
// fecha sola significa el FINAL de ese día. Antes viajaba tal cual y Postgres
// la leía como 00:00:00, o sea que `date=2026-09-01` devolvía el saldo con el
// que ARRANCÓ el 1 de septiembre, sin ningún movimiento de la jornada.
$date   = (string) (validateHttp('date') ?: '');
$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!Date::isRangeBound($date)) {
    apiError('Formato de fecha inválido', 422);
}
$date = Date::rangeEnd($date);
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

// Sucursal efectiva: VIEW_OUTLET_ID (header X-Outlet-Id del selector del
// frontend) gana sobre el OUTLET_ID del JWT; `''` es el modo "Todas", que acá
// SÍ tiene sentido (el saldo se agrega company-wide). context/52: el scope
// viaja BINDEADO al lector único, ya no como fragmento SQL interpolado
// (`Roc::build`), así que este endpoint no arma más el $roc.
$effectiveOutletId = defined('VIEW_OUTLET_ID') ? (string) constant('VIEW_OUTLET_ID') : (string) OUTLET_ID;
if (!preg_match($uuidRe, $effectiveOutletId)) {
    $effectiveOutletId = '';
}

apiOk($svc->levels($date, COMPANY_ID, $effectiveOutletId));
