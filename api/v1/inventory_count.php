<?php
/**
 * /api/v1/inventory_count.php — conteo de inventario (toma física).
 *
 * GET  ?action=list[&outletId=<uuid>][&status=<0|1|2>][&limit=50][&offset=0]
 * GET  ?action=get&id=<uuid>
 * POST { action: "create", outletId, locationId?, note? }
 * POST { action: "setQty", id, itemId, qty }
 * POST { action: "bulkSetQty", id, rows: [{itemId, qty}] }
 * POST { action: "finish", id }
 * POST { action: "cancel", id }
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/InventoryCountService.php';

use Punto\Api\Services\InventoryCountService;

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$svc = new InventoryCountService();

function isValidUuid(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'list');

    if ($action === 'list') {
        $outletId = isset($_GET['outletId']) && isValidUuid($_GET['outletId']) ? $_GET['outletId'] : null;
        $status   = isset($_GET['status'])   && is_numeric($_GET['status'])    ? (int) $_GET['status'] : null;
        $limit    = max(1, min(200, (int) ($_GET['limit']  ?? 50)));
        $offset   = max(0, (int) ($_GET['offset'] ?? 0));

        $result = $svc->list($companyId, $outletId, $status, $limit, $offset);
        apiOk($result);
    }

    if ($action === 'get') {
        $id = trim((string) ($_GET['id'] ?? ''));
        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }

        $data = $svc->get($id, $companyId);
        if ($data === null) {
            apiError('Sesión no encontrada', 404);
        }

        apiOk($data);
    }

    apiError('action desconocida', 400);
}

if ($method === 'POST') {
    // Cerrar un conteo ajusta stock contra lo contado: mismo criterio que
    // /v1/stock_adjustment. La UI ya gateaba con esta clave
    // (panel-auth-guard.tsx) pero el endpoint no — llamando la API directo
    // cualquier usuario del comercio podía cerrar un conteo y mover inventario.
    // El GET queda con la auth de panel (ver production.php/waste.php).
    if (!hasPermission('inventory.stock.adjust')) {
        apiError('No tenés permiso para esta acción (requiere: inventory.stock.adjust)', 403);
    }

    $body   = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
    $action = (string) ($body['action'] ?? '');

    if ($action === 'create') {
        $outletId   = trim((string) ($body['outletId']   ?? ''));
        $locationId = trim((string) ($body['locationId'] ?? '')) ?: null;
        $note       = trim((string) ($body['note']       ?? '')) ?: null;

        if (!isValidUuid($outletId)) {
            apiError('outletId inválido', 400);
        }
        if ($locationId !== null && !isValidUuid($locationId)) {
            apiError('locationId inválido', 400);
        }

        try {
            $result = $svc->create($companyId, $outletId, $locationId, $userId, $note);
            apiOk($result);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 422);
        }
    }

    if ($action === 'setQty') {
        $id     = trim((string) ($body['id']     ?? ''));
        $itemId = trim((string) ($body['itemId'] ?? ''));
        $qty    = $body['qty'] ?? null;

        if (!isValidUuid($id) || !isValidUuid($itemId)) {
            apiError('id o itemId inválido', 400);
        }
        if (!is_numeric($qty)) {
            apiError('qty debe ser numérico', 400);
        }

        $ok = $svc->setCountedQty($id, $itemId, (float) $qty, $userId, $companyId);
        if (!$ok) {
            apiError('Sesión no encontrada o no está en progreso', 404);
        }
        apiOk(['ok' => true]);
    }

    if ($action === 'bulkSetQty') {
        $id   = trim((string) ($body['id'] ?? ''));
        $rows = $body['rows'] ?? [];

        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }
        if (!is_array($rows) || count($rows) === 0) {
            apiError('rows debe ser un array no vacío', 400);
        }

        $validated = [];
        foreach ($rows as $r) {
            if (!isset($r['itemId'], $r['qty']) || !isValidUuid((string) $r['itemId']) || !is_numeric($r['qty'])) {
                apiError('Fila inválida en rows: se requieren itemId (UUID) y qty (numérico)', 400);
            }
            $validated[] = ['itemId' => $r['itemId'], 'qty' => (float) $r['qty']];
        }

        $count = $svc->bulkSetCountedQty($id, $validated, $userId, $companyId);
        apiOk(['updatedCount' => $count]);
    }

    if ($action === 'finish') {
        $id = trim((string) ($body['id'] ?? ''));
        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }

        try {
            $result = $svc->finish($id, $companyId, $userId);
            apiOk($result);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 409);
        } catch (\InvalidArgumentException $e) {
            apiError($e->getMessage(), 404);
        }
    }

    if ($action === 'cancel') {
        $id = trim((string) ($body['id'] ?? ''));
        if (!isValidUuid($id)) {
            apiError('id inválido', 400);
        }

        try {
            $ok = $svc->cancel($id, $companyId);
            if (!$ok) {
                apiError('Sesión no encontrada o ya finalizada', 404);
            }
            apiOk(['ok' => true]);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), (int) $e->getCode() ?: 409);
        }
    }

    apiError('action desconocida', 400);
}

apiError('Método no soportado', 405);
