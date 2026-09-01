<?php
/**
 * REST canónico (API compartida /api) — Historial de Stock / Inventario (raw).
 *
 *   GET /v1/reports/inventory?dataset=movements&from=&to=&itemId=&byDay=
 *
 *   dataset (sólo 'movements' soportado): filas crudas de stock (item/sucursal/
 *   depósito/usuario resueltos).
 *
 * El dataset `widget` (KPIs cost/sell/total) NO está acá: sigue sirviéndose por
 * el panel local (panel/API/v1/reports/inventory.php) porque corregir su cálculo
 * (legacy roto en PG → 0,0,0 con datos reales) es una decisión de PRODUCTO
 * (semántica sucursal vs company). El BFF ramifica las dos rutas.
 *
 * Auth: realms `panel` y `api` (lectura programatica: API keys / MCP). Tenant por COMPANY_ID + outlet.
 */

require_once __DIR__ . '/../../bootstrap.php';

use Punto\App\Helpers\Date;

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\InventoryService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$dataset = (string) (validateHttp('dataset') ?: 'movements');

if ($dataset === 'movements') {
    $itemId = (string) (validateHttp('itemId') ?: '');
    $byDay  = (bool) validateHttp('byDay');

    // Rango del reporte. Una fecha SOLA en `to` significa el FINAL de ese dia
    // (ver Date::reportRange): mandar `to=2026-09-01` y perder todo lo de ese
    // dia despues de medianoche era el bug que reporto el agente IA.
    [$from, $to, $rangeOk] = Date::reportRange(validateHttp('from'), validateHttp('to'));

    $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    if (!$itemId && !$rangeOk) {
        apiError('Formato de fecha inválido', 422);
    }
    if ($itemId && !preg_match($uuidRe, $itemId)) {
        apiError('itemId inválido', 422);
    }

    try {
        $roc = \Punto\Api\Reports\Roc::build((string) COMPANY_ID, (string) OUTLET_ID);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 500);
    }

    apiOk($svc->movements($from, $to, $itemId, $byDay, $roc, (string) COMPANY_ID));
    return;
}

apiError('dataset desconocido: ' . $dataset, 422);
