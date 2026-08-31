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

$ctx = apiAuthTenant(['panel', 'api']);
$svc = new \Punto\Api\Reports\InventoryService();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$dataset = (string) (validateHttp('dataset') ?: 'movements');

if ($dataset === 'movements') {
    $itemId = (string) (validateHttp('itemId') ?: '');
    $byDay  = (bool) validateHttp('byDay');

    $from = (string) (validateHttp('from') ?: '');
    $to   = (string) (validateHttp('to')   ?: '');
    if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
    if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

    $dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
    $uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    if (!$itemId && (!preg_match($dateRe, $from) || !preg_match($dateRe, $to))) {
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
