<?php
/**
 * /api/v1/remisiones.php — nota de remisión (context/42-remision.md).
 *
 * Cubre los motivos de traslado donde el destino NO es un outlet propio:
 * venta, devolución a proveedor, consignación, exposición/demostración,
 * compra. El traslado entre depósitos/sucursales propios sigue siendo
 * /v1/stock_transfer — no se duplica acá.
 *
 * GET  ?action=list[&outletId&motivo&status&limit&offset&dateFrom&dateTo]
 * GET  ?action=get&id=<uuid>
 * POST { action: "create", motivo, outletId, locationId?, destinationContactId?,
 *        destinationNote?, transactionId?, transferDate?, note?,
 *        items: [{itemId, qty, note?}] }
 * POST { action: "cancel", id }
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/RemisionService.php';

use Punto\Api\Services\RemisionService;

$ctx       = apiAuthTenant(['panel']);
$companyId = $ctx['companyId'];
$userId    = $ctx['userId'];
$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$svc = new RemisionService();

function isValidUuidRemision(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

if ($method === 'GET') {
    $action = (string) ($_GET['action'] ?? 'list');

    if ($action === 'list') {
        $filters = [];
        if (isset($_GET['outletId']) && isValidUuidRemision($_GET['outletId'])) {
            $filters['outletId'] = $_GET['outletId'];
        }
        if (!empty($_GET['motivo'])) {
            $filters['motivo'] = (string) $_GET['motivo'];
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
        if (!isValidUuidRemision($id)) {
            apiError('id inválido', 400);
        }
        $data = $svc->get($id, $companyId);
        if ($data === null) {
            apiError('Remisión no encontrada', 404);
        }
        apiOk($data);
    }

    apiError('action desconocida', 400);
}

if ($method === 'POST') {
    $body   = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
    $action = (string) ($body['action'] ?? '');

    if ($action === 'create') {
        $motivo               = trim((string) ($body['motivo'] ?? ''));
        $outletId             = trim((string) ($body['outletId'] ?? ''));
        $locationId           = trim((string) ($body['locationId'] ?? '')) ?: null;
        $destinationContactId = trim((string) ($body['destinationContactId'] ?? '')) ?: null;
        $destinationNote      = trim((string) ($body['destinationNote'] ?? '')) ?: null;
        $transactionId        = trim((string) ($body['transactionId'] ?? '')) ?: null;
        $transferDate         = trim((string) ($body['transferDate'] ?? '')) ?: null;
        $note                 = trim((string) ($body['note'] ?? '')) ?: null;
        $items                = $body['items'] ?? [];

        if (!isValidUuidRemision($outletId)) {
            apiError('outletId inválido', 400);
        }
        if ($locationId !== null && !isValidUuidRemision($locationId)) {
            apiError('locationId inválido', 400);
        }
        if ($destinationContactId !== null && !isValidUuidRemision($destinationContactId)) {
            apiError('destinationContactId inválido', 400);
        }
        if ($transactionId !== null && !isValidUuidRemision($transactionId)) {
            apiError('transactionId inválido', 400);
        }
        if (!is_array($items) || count($items) === 0) {
            apiError('items debe ser un array no vacío', 400);
        }

        $validatedItems = [];
        foreach ($items as $item) {
            if (!isset($item['itemId'], $item['qty']) || !isValidUuidRemision((string) $item['itemId']) || !is_numeric($item['qty'])) {
                apiError('Item inválido: se requieren itemId (UUID) y qty (numérico)', 400);
            }
            $validatedItems[] = [
                'itemId' => $item['itemId'],
                'qty'    => (float) $item['qty'],
                'note'   => isset($item['note']) ? trim((string) $item['note']) ?: null : null,
            ];
        }

        try {
            $result = $svc->create(
                $companyId,
                $userId,
                $motivo,
                $outletId,
                $locationId,
                $destinationContactId,
                $destinationNote,
                $transactionId,
                $transferDate,
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
        if (!isValidUuidRemision($id)) {
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
