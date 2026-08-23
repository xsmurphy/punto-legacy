<?php
declare(strict_types=1);

/**
 * /api/v1/returns.php — devoluciones de venta.
 *
 * POST { action: "create", parentTransactionId,
 *        items: [{itemId, qty, restock?: bool, itemSoldId?: string}],
 *        refundMode: 'cash'|'credit', note? }
 * GET  ?action=listForParent&parentId=UUID
 * GET  ?action=returnOptions&parentId=UUID  → D2 (context/40): qué es posible
 *      reponer por línea vendida + cupo disponible. La UI arma el form de
 *      devolución con esto en vez de listar los ítems por su cuenta.
 *
 * D3 (context/40): `settingReturnRefund` puede rechazar un `refundMode` que
 * no matchea la política del tenant — ver `ReturnService::create()`, 422
 * con mensaje explícito.
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
$registerId = $ctx['registerId'] ?: null;  // null cuando se llama desde panel sin caja abierta

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

    // ── Gate de autorización ───────────────────────────────────────────────
    // Una devolución devuelve plata del cajón y revierte stock: es la
    // contracara de la venta, no una operación más del mostrador.
    //
    // Realm `pos-app`: la sesión del device se emite con roleId='1'
    // (DeviceAuth::buildToken) → seed `owner` → este gate SIEMPRE pasa en la
    // caja. No puede romper el mostrador. Es efectivo hoy para el realm
    // `panel` (rol real del operador) y lo será para el POS cuando exista
    // sesión de operador, sin tocar este call-site.
    if (!hasPermission('pos.sale.refund')) {
        apiError('No tenés permiso para esta acción (requiere: pos.sale.refund)', 403);
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

        $validated = [
            'itemId' => $itemId,
            'qty'    => (float) $qty,
        ];
        // D2 (context/40): decisión del cajero por línea, opcional — ausente
        // = default de StockReversalPolicy::classifyLine() para ese itemId.
        if (array_key_exists('restock', $item)) {
            $validated['restock'] = !empty($item['restock']);
        }
        if (!empty($item['itemSoldId'])) {
            $soldId = trim((string) $item['itemSoldId']);
            if (!isValidUuidReturn($soldId)) {
                apiError('itemSoldId inválido en items', 400);
            }
            $validated['itemSoldId'] = $soldId;
        }

        $validatedItems[] = $validated;
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

    if (!isValidUuidReturn($parentId)) {
        apiError('parentId inválido', 400);
    }

    // Lectura del historial de devoluciones de una venta: mismo material que
    // el detalle de la transacción padre, gateado con el permiso de lectura
    // de ventas (no con pos.sale.refund, que autoriza a EJECUTAR la
    // devolución — ver reports/transactions.php, misma clave).
    if (!hasPermission('reports.sales.view')) {
        apiError('No tenés permiso para esta acción (requiere: reports.sales.view)', 403);
    }

    if ($action === 'listForParent') {
        $rows = $svc->listForParent($parentId, $companyId);
        apiOk(['returns' => $rows]);
    }

    if ($action === 'returnOptions') {
        apiOk(['lines' => $svc->returnOptions($companyId, $parentId)]);
    }

    apiError('action desconocida', 400);
}

apiError('Método no soportado', 405);
