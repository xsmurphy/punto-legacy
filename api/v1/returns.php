<?php
declare(strict_types=1);

/**
 * /api/v1/returns.php — devoluciones de venta.
 *
 * POST { action: "create", parentTransactionId, items: [{itemId, qty}], refundMode: 'cash'|'credit', note? }
 * GET  ?action=listForParent&parentId=UUID
 *
 * Auth: JWT de tenant (panel o pos-app).
 * Responde con envelope canónico { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/ReturnService.php';

use Punto\Api\Services\ReturnService;

$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$userId     = $ctx['userId'];
$outletId   = $ctx['outletId']   ?? '';
$registerId = $ctx['registerId'] ?? '';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$svc = new ReturnService();

function isValidUuidReturn(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

// ── POST → create ─────────────────────────────────────────────────────────────

if ($method === 'POST') {
    $body   = (array) (json_decode(file_get_contents('php://input'), true) ?? []);
    $action = (string) ($body['action'] ?? '');

    if ($action !== 'create') {
        apiError('action desconocida', 400);
    }

    $parentTransactionId = trim((string) ($body['parentTransactionId'] ?? ''));
    $items               = $body['items'] ?? [];
    $refundMode          = trim((string) ($body['refundMode'] ?? ''));
    $note                = isset($body['note']) ? trim((string) $body['note']) : null;

    if (!isValidUuidReturn($parentTransactionId)) {
        apiError('parentTransactionId inválido', 400);
    }
    if (!is_array($items) || count($items) === 0) {
        apiError('items debe ser un array no vacío', 400);
    }
    if (!in_array($refundMode, ['cash', 'credit'], true)) {
        apiError('refundMode debe ser "cash" o "credit"', 400);
    }
    if (empty($outletId) || !isValidUuidReturn($outletId)) {
        apiError('outletId no disponible en el contexto de sesión', 403);
    }

    // Validar y normalizar items
    $validatedItems = [];
    foreach ($items as $item) {
        $itemId = trim((string) ($item['itemId'] ?? ''));
        $qty    = $item['qty'] ?? null;

        if (!isValidUuidReturn($itemId)) {
            apiError('itemId inválido en items', 400);
        }
        if (!is_numeric($qty) || (float) $qty <= 0) {
            apiError('qty debe ser mayor a 0 en todos los items', 400);
        }

        $validatedItems[] = [
            'itemId' => $itemId,
            'qty'    => (float) $qty,
        ];
    }

    try {
        $result = $svc->create(
            $companyId,
            $userId,
            $outletId,
            $registerId,
            $parentTransactionId,
            $validatedItems,
            $refundMode,
            $note ?: null
        );
        apiOk($result);
    } catch (\InvalidArgumentException $e) {
        apiError($e->getMessage(), 422);
    } catch (\RuntimeException $e) {
        error_log('[returns] ' . $e->getMessage());
        apiError('No se pudo procesar la devolución', 500);
    }
}

// ── GET → listForParent ───────────────────────────────────────────────────────

if ($method === 'GET') {
    $action   = (string) ($_GET['action'] ?? '');
    $parentId = trim((string) ($_GET['parentId'] ?? ''));

    if ($action !== 'listForParent') {
        apiError('action desconocida', 400);
    }
    if (!isValidUuidReturn($parentId)) {
        apiError('parentId inválido', 400);
    }

    $rows = $svc->listForParent($parentId, $companyId);
    apiOk(['returns' => $rows]);
}

apiError('Método no soportado', 405);
