<?php
/**
 * /api/v1/sales.php — guardado de ventas (API compartida del sistema).
 *
 *   POST { uid, type, sale[], subtotal, tax, discount, payment[], ... }
 *     → guarda la venta y devuelve {success, transactionId, uid}
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 *
 * Strangler-fig de `app/action.php?action=processData` — ver SaleService.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/SaleService.php';

$ctx = apiAuthTenant();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

// El front manda el payload completo como JSON dentro de `data[]` (idéntico al legacy
// processData, para que la cola offline pueda enrutar sin reformatear el payload).
$rawData = $_POST['data'] ?? null;
if (is_array($rawData)) {
    $rawData = $rawData[0] ?? null;
}

if (!is_string($rawData) || $rawData === '') {
    apiError('Falta el payload data[]', 422);
}

$decoded = json_decode($rawData, true);
if (!is_array($decoded)) {
    apiError('Payload data[] no es JSON válido', 422);
}

// El front envuelve la venta en `{ transaction: { ... }, uid }`. Aceptamos ambas formas.
$transaction = $decoded['transaction'] ?? $decoded;
if (isset($decoded['uid']) && !isset($transaction['uid'])) {
    $transaction['uid'] = $decoded['uid'];
}

if (empty($transaction['uid'])) {
    apiError('Falta uid en el payload', 422);
}

if (!isset($transaction['type']) || !in_array((int) $transaction['type'], [0, 3], true)) {
    apiError('SaleService::save sólo cubre type ∈ {0, 3} en este sub-slice', 422);
}

$result = (new SaleService($ctx))->save($transaction);

if (empty($result['success'])) {
    apiError($result['error'] ?? 'Fallo al guardar la venta', 500);
}

apiOk($result);
