<?php
/**
 * REST canónico — Reporte de Historial de Stock / Inventario (motor ERP, raw).
 *
 *   GET /API/v1/reports/inventory?dataset=<tipo>&from=&to=&itemId=&byDay=
 *
 *   dataset (default 'movements'):
 *     movements → filas de stock crudas+enriquecidas (item/sucursal/depósito/usuario resueltos).
 *                 Filtros: itemId (historial de un ítem, sin rango de fecha) y byDay (agrupa por día).
 *     widget    → KPIs { cost, sell, total } del valor de inventario.
 *
 * Todo CRUDO (números, fechas ISO, source como key). El BFF forwardea; el front formatea,
 * traduce `source` y arma el markup. Ver context/02-arquitectura.md § REGLA RAÍZ 2.
 *
 * Auth: JWT. Tenant por COMPANY_ID del JWT. Respuesta: envelope canónico.
 */

require_once __DIR__ . '/../../lib/api_middleware.php';
apiMiddleware();

require_once __DIR__ . '/../../../lib/reports/ReportInventoryService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$dataset = (string) (validateHttp('dataset') ?: 'movements');
$svc     = new ReportInventoryService();

if ($dataset === 'widget') {
    apiOk($svc->widget());
    return;
}

if ($dataset === 'movements') {
    $itemId = (string) (validateHttp('itemId') ?: '');
    $byDay  = (bool) validateHttp('byDay');

    $from = (string) (validateHttp('from') ?: '');
    $to   = (string) (validateHttp('to')   ?: '');
    if ($from === '') { $from = date('Y-m-d 00:00:00', strtotime('-7 days')); }
    if ($to   === '') { $to   = date('Y-m-d 23:59:59'); }

    $dateRe = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';
    if (!$itemId && (!preg_match($dateRe, $from) || !preg_match($dateRe, $to))) {
        apiError('Formato de fecha inválido', 422);
    }
    // itemId, si viene, debe ser UUID (evita interpolación/abuso).
    if ($itemId && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $itemId)) {
        apiError('itemId inválido', 422);
    }

    $roc = getROC(1);
    apiOk($svc->movements($from, $to, $itemId, $byDay, $roc));
    return;
}

apiError('dataset desconocido: ' . $dataset, 422);
