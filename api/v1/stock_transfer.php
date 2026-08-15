<?php
/**
 * /api/v1/stock_transfer.php — transferencias de stock entre outlets/depósitos.
 *
 * GET  ?action=list[&fromOutletId&toOutletId&status&limit&offset&dateFrom&dateTo]
 * GET  ?action=get&id=<uuid>
 * POST { action: "create", from: {outletId, locationId?}, to: {outletId, locationId?}, note?, items: [{itemId, qty}] }
 * POST { action: "cancel", id }
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/StockTransferService.php';

use Punto\Api\Services\StockTransferService;

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$svc = new StockTransferService();

function isValidUuid(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'list');

    if ($action === 'list') {
        $filters = [];
        if (isset($_GET['fromOutletId']) && isValidUuid($_GET['fromOutletId'])) {
            $filters['fromOutletId'] = $_GET['fromOutletId'];
        }
        if (isset($_GET['toOutletId']) && isValidUuid($_GET['toOutletId'])) {
            $filters['toOutletId'] = $_GET['toOutletId'];
        }
        if (isset($_GET['status']) && is_numeric($_GET['status'])) {
            $filters['status'] = (int) $_GET['status'];
        }
        if (!empty($_GET['dateFrom'])) {
            $filters['dateFrom'] = $_GET['dateFrom'];
        }
        if (!empty($_GET['dateTo'])) {
            $filters['dateTo'] = $_GET['dateTo'];
        }
        $filters['limit']  = max(1, min(200, (int) ($_GET['limit']  ?? 50)));
        $filters['offset'] = max(0, (int) ($_GET['offset'] ?? 0));

        apiOk($svc->list($companyId, $filters));
    }

    if ($action === 'get') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }
        $data = $svc->get($id, $companyId);
        if ($data === null) {
            apiError('Transferencia no encontrada', 404);
        }
        apiOk($data);
    }

    apiError('action desconocida', 400);
}

if ($method === 'POST') {
    // Mover stock entre sucursales es una acción de inventario, no algo que
    // habilite el solo hecho de tener acceso al panel: sin este gate cualquier
    // usuario del comercio podía transferir mercadería. El GET queda con la
    // auth de panel, mismo criterio que production.php/waste.php.
    if (!hasPermission('inventory.transfer')) {
        apiError('No tenés permiso para esta acción (requiere: inventory.transfer)', 403);
    }

    $body   = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
    $action = (string) ($body['action'] ?? '');

    if ($action === 'create') {
        $from  = $body['from'] ?? [];
        $to    = $body['to']   ?? [];
        $note  = trim((string) ($body['note'] ?? '')) ?: null;
        $items = $body['items'] ?? [];

        $fromOutletId   = trim((string) ($from['outletId']   ?? ''));
        $toOutletId     = trim((string) ($to['outletId']     ?? ''));
        $fromLocationId = trim((string) ($from['locationId'] ?? '')) ?: null;
        $toLocationId   = trim((string) ($to['locationId']   ?? '')) ?: null;

        if (!isValidUuid($fromOutletId)) {
            apiError('from.outletId inválido', 400);
        }
        if (!isValidUuid($toOutletId)) {
            apiError('to.outletId inválido', 400);
        }
        if ($fromLocationId !== null && !isValidUuid($fromLocationId)) {
            apiError('from.locationId inválido', 400);
        }
        if ($toLocationId !== null && !isValidUuid($toLocationId)) {
            apiError('to.locationId inválido', 400);
        }
        if (!is_array($items) || count($items) === 0) {
            apiError('items debe ser un array no vacío', 400);
        }

        $validatedItems = [];
        foreach ($items as $item) {
            if (!isset($item['itemId'], $item['qty']) || !isValidUuid((string) $item['itemId']) || !is_numeric($item['qty'])) {
                apiError('Item inválido: se requieren itemId (UUID) y qty (numérico)', 400);
            }
            $validatedItems[] = ['itemId' => $item['itemId'], 'qty' => (float) $item['qty']];
        }

        try {
            $result = $svc->create(
                $companyId,
                $userId,
                ['outletId' => $fromOutletId, 'locationId' => $fromLocationId],
                ['outletId' => $toOutletId,   'locationId' => $toLocationId],
                $note,
                $validatedItems
            );
            apiOk($result);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 422);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 500);
        }
    }

    if ($action === 'cancel') {
        $id = trim((string) ($body['id'] ?? ''));
        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }

        try {
            $result = $svc->cancel($id, $companyId, $userId);
            apiOk($result);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 404);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 409);
        }
    }

    apiError('action desconocida', 400);
}

apiError('Método no soportado', 405);
