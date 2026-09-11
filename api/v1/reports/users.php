<?php
/**
 * REST canónico (API compartida /api) — Reporte de Ventas por Usuarios / Recursos (raw).
 *
 *   GET /v1/reports/users?from=&to=[&view=summary|commissions] → filas crudas por usuario.
 *
 * Sin `view`: la respuesta histórica (array plano de filas). NO se toca — la
 * consumen la pestaña Detalle del panel y los lectores programáticos.
 *   `view=summary`     → { totals, ranking, daily } para el dashboard.
 *   `view=commissions` → { sellers, totals } con el detalle liquidable.
 *
 * Sin formatear, sin HTML. Auth: realms `panel` y `api` (lectura programatica: API keys / MCP). Tenant por COMPANY_ID del JWT.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\UsersService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Ventas por usuario: cuánto vendió cada empleado. Es el reporte de ventas abierto por vendedor — NO `contacts.user.view`, que gobierna la FICHA del empleado, no sus montos.
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

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
if (!preg_match($uuidRe, (string) COMPANY_ID)) {
    apiError('Contexto de empresa inválido', 500);
}

// El gate es el mismo para las tres vistas a propósito: todas responden la
// misma pregunta —cuánto vendió cada persona— con distinto grano. Una clave
// aparte para las comisiones sugeriría un permiso que el catálogo no tiene.
$view = (string) (validateHttp('view') ?: '');
if (!in_array($view, ['', 'summary', 'commissions'], true)) {
    apiError('Vista no soportada', 422);
}

if ($view === 'summary') {
    apiOk($svc->summary($from, $to, COMPANY_ID));
} elseif ($view === 'commissions') {
    apiOk($svc->commissions($from, $to, COMPANY_ID));
} else {
    apiOk($svc->salesByUser($from, $to, COMPANY_ID));
}
