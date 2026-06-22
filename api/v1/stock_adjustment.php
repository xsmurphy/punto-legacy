<?php
/**
 * /api/v1/stock_adjustment.php — ajuste manual de stock.
 *
 * POST { action: "create", outletId, locationId?, reason, items: [{itemId, qty, type, unitCost?, note?}] }
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/StockAdjustmentService.php';

use Punto\Api\Services\StockAdjustmentService;

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$svc = new StockAdjustmentService();

function isValidUuid(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

if ($method !== 'POST') {
    apiError('Método no soportado', 405);
}

$body   = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
$action = (string) ($body['action'] ?? '');

if ($action === 'create') {
    $outletId   = trim((string) ($body['outletId']   ?? ''));
    $locationId = trim((string) ($body['locationId'] ?? '')) ?: null;
    $reason     = trim((string) ($body['reason']     ?? ''));
    $items      = $body['items'] ?? [];

    if (!isValidUuid($outletId)) {
        apiError('outletId inválido', 400);
    }
    if ($locationId !== null && !isValidUuid($locationId)) {
        apiError('locationId inválido', 400);
    }
    if (empty($reason)) {
        apiError('reason es requerido', 400);
    }
    if (!is_array($items) || count($items) === 0) {
        apiError('items debe ser un array no vacío', 400);
    }

    $validatedItems = [];
    foreach ($items as $item) {
        $itemId = trim((string) ($item['itemId'] ?? ''));
        $qty    = $item['qty'] ?? null;
        $type   = trim((string) ($item['type'] ?? ''));

        if (!isValidUuid($itemId)) {
            apiError('itemId inválido en items', 400);
        }
        if (!is_numeric($qty) || (float) $qty <= 0) {
            apiError('qty debe ser un número mayor a 0', 400);
        }
        if (!in_array($type, ['+', '-'], true)) {
            apiError('type debe ser "+" o "-"', 400);
        }

        $unitCost = isset($item['unitCost']) && is_numeric($item['unitCost']) ? (float) $item['unitCost'] : null;
        $note     = isset($item['note']) ? trim((string) $item['note']) : '';

        $validatedItems[] = [
            'itemId'   => $itemId,
            'qty'      => (float) $qty,
            'type'     => $type,
            'unitCost' => $unitCost,
            'note'     => $note,
        ];
    }

    try {
        $result = $svc->create($companyId, $userId, $outletId, $locationId, $reason, $validatedItems);
        apiOk($result);
    } catch (\InvalidArgumentException $e) {
        apiError($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        error_log('[stock_adjustment] ' . $e->getMessage());
        apiError('No se pudo aplicar el ajuste', 500);
    }
}

apiError('action desconocida', 400);
