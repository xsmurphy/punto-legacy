<?php
/**
 * REST canónico (API compartida /api) — Reporte de Órdenes (motor ERP, raw).
 *
 *   GET /v1/reports/orders?from=&to=  → { rows } CRUDO (transaction type=12).
 *
 * Lectura sin formatear (el front mapea estado→badge y formatea fecha/monto).
 * Auth: realms `panel` y `api` (lectura programatica: API keys / MCP). Tenant por
 * COMPANY_ID + outlet (ROC).
 * Ver REGLA RAÍZ 2. Mismo patrón que los demás reportes de la /api compartida.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

apiAuthTenant(['panel', 'api']);

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

// Franja horaria del reporte (F1 de context/67). Es una dimensión APARTE del
// rango: el rango es un intervalo CONTINUO, así que "del 1 al 30 de 07:00 a
// 11:59" mandado como from/to incluye las noches del medio. `hourFrom`/`hourTo`
// se repiten en cada día del rango. Sin ellos la banda es vacía y la query sale
// byte por byte como salía antes de esta feature.
[$hours, $hoursOk] = \Punto\Api\Reports\HourBand::fromRequest(validateHttp('hourFrom'), validateHttp('hourTo'));
if (!$hoursOk) {
    apiError('Formato de franja horaria inválido (esperado HH:MM o HH:MM:SS)', 422);
}

// Roc::build respeta VIEW_OUTLET_ID si el browser mandó X-Outlet-Id (dropdown del logo).
try {
    $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
} catch (\RuntimeException $e) {
    apiError($e->getMessage(), 500);
}

$customerId = trim((string) ($_GET['customerId'] ?? '')) ?: null;

$svc = new \Punto\Api\Reports\OrdersService();
apiOk(['rows' => $svc->listOrders($from, $to, $roc, COMPANY_ID, $customerId, $hours)]);
