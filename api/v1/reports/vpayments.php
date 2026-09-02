<?php
/**
 * REST canónico (API compartida /api) — Pagos ePOS / vPayments (motor ERP, raw — gateway externo).
 *
 *   GET /v1/reports/vpayments?from=&to=   → registros de pagos ePOS + totales, CRUDO.
 *
 * La capa API es la única que habla con el servicio externo (proxy a get_vpayments → Bancard/Dinelco).
 * Read-only. Sin formatear: el front formatea + arma tabla/donut. Auth: realms `panel` y `api` (lectura programatica: API keys / MCP). Ver REGLA RAÍZ 2.
 *
 * Port FIEL de panel/API/v1/reports/vpayments.php (Fase 2 del desacople de /panel — último
 * reporte). Cambios: `apiMiddleware()` → `apiAuthTenant(['panel'])`; service en namespace
 * `Punto\Api\Reports\VPaymentsService` (cae el prefijo `Report`). companyId del contexto.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

/* ───────── Gate de LECTURA ─────────────────────────────────────────────────
 *
 * Pagos ePOS (Bancard/Dinelco): cobros de ventas del comercio. El módulo está muerto y sin consumidor en el front, pero el endpoint responde y hay que gatearlo igual que el resto.
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

$svc = new \Punto\Api\Reports\VPaymentsService();
apiOk($svc->general($from, $to, COMPANY_ID));
