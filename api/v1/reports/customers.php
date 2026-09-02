<?php
/**
 * REST canónico (API compartida /api) — Reporte de Clientes (raw).
 *
 *   GET /v1/reports/customers?from=&to=                    → { rows: [...] }
 *   GET /v1/reports/customers?from=&to=&include=dashboard  → { dashboard: {...} }
 *   GET /v1/reports/customers?include=geo                  → { geo: {...} }
 *   GET /v1/reports/customers?from=&to=&include=rows,dashboard,geo
 *
 * SIN `include` la respuesta es exactamente la de siempre (`{ rows }`): hay
 * consumidores programáticos del endpoint (read-tools del agente / MCP) que ya
 * leen ese shape, y ninguno debe empezar a pagar el costo de las secciones
 * nuevas. Con `include` se devuelven SOLO las secciones pedidas — así el tab
 * geográfico, que es el más caro, no se calcula mientras nadie lo abra.
 *
 * `geo` ignora `from`/`to` a propósito: es el padrón de clientes, no el
 * período (ver CustomersService::geography).
 *
 * Read-only. Auth: realms `panel` y `api` (lectura programatica: API keys / MCP).
 * Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\CustomersService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Consumo por cliente: ranking, KPIs y padrón geográfico. Es el reporte de ventas abierto por cliente — NO `contacts.customer.view`, que habilita atender a un cliente en el mostrador y la tiene hasta el rol `cashier`; con esa clave un cajero se llevaba el ranking de facturación del comercio.
 *
 * Va por `OperatorContext::requirePermission()` y no por `hasPermission()` a
 * secas: es la puerta ÚNICA que mide el permiso contra la PERSONA en los tres
 * realms (por qué, en el docblock de `api/lib/Auth/OperatorContext.php`). Acá
 * los realms son `panel` y `api`, donde las dos resuelven igual — usarla de
 * todos modos deja el gate correcto si mañana el endpoint acepta `pos-app`.
 */
require_once __DIR__ . '/../../lib/Auth/OperatorContext.php';
\Punto\Api\Auth\OperatorContext::requirePermission($ctx, 'reports.sales.view');

// Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
// (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
// dia despues de medianoche era el bug que reporto el agente IA.
[$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));

if (!$rangeOk) {
    apiError('Formato de fecha inválido', 422);
}

// Whitelist explícita: `include` viene del request, así que nunca se usa para
// resolver un método ni se concatena a SQL.
$SECCIONES = ['rows', 'dashboard', 'geo'];
$includeIn = trim((string) (validateHttp('include') ?: ''));
if ($includeIn === '') {
    $secciones = ['rows'];
} else {
    $pedidas   = array_map('trim', explode(',', strtolower($includeIn)));
    $secciones = array_values(array_intersect($SECCIONES, $pedidas));
    if (!$secciones) {
        apiError('Sección inválida. Válidas: ' . implode(', ', $SECCIONES), 422);
    }
}

try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$out = [];
if (in_array('rows', $secciones, true)) {
    $out['rows'] = $svc->ranking($from, $to, $roc, (string) COMPANY_ID);
}
if (in_array('dashboard', $secciones, true)) {
    $out['dashboard'] = $svc->dashboard($from, $to, (string) COMPANY_ID, (string) OUTLET_ID);
}
if (in_array('geo', $secciones, true)) {
    $out['geo'] = $svc->geography((string) COMPANY_ID);
}

apiOk($out);
