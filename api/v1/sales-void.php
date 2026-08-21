<?php
declare(strict_types=1);

/**
 * /api/v1/sales-void.php — anulación de ventas (F1+F2, context/40-anulacion-y-nota-credito.md).
 *
 *   GET  ?id=<txId>                              → { canVoid: {allowed,reason,expiresAt}, lines: [...] }
 *   POST { id, reason, lines?: [{itemSoldId|itemId, restock}] } → anula la venta
 *
 * Auth: JWT de tenant (panel o pos-app) — mismo realm que `returns.php`
 * (la acción hermana "Devolución" en el mismo detalle de transacción de
 * `/pos`). Permiso `pos.sale.void` (ya existe en PermissionCatalog, ya
 * sembrado en owner/manager — reusado, no se crea ninguno nuevo).
 *
 * Responde con envelope canónico { ok, data }.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/SaleVoidService.php';

use Punto\Api\Services\SaleVoidService;

$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$userId     = $ctx['userId'];
$outletId   = $ctx['outletId']   ?: null;
$registerId = $ctx['registerId'] ?: null; // null cuando se llama desde panel sin caja abierta

if (!hasPermission('pos.sale.void')) {
    apiError('No tenés permiso para esta acción (requiere: pos.sale.void)', 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$svc    = new SaleVoidService();

function isValidUuidSaleVoid(string $s): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

// ── GET → estado + opciones de reposición ──────────────────────────────────

if ($method === 'GET') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    if (!isValidUuidSaleVoid($transactionId)) {
        apiError('id inválido', 422);
    }

    apiOk([
        'canVoid' => $svc->canVoid($companyId, $transactionId),
        'lines'   => $svc->voidOptions($companyId, $transactionId),
    ]);
}

// ── POST → anular ────────────────────────────────────────────────────────

if ($method === 'POST') {
    $body = (array) (json_decode(file_get_contents('php://input'), true) ?? []);

    $transactionId = trim((string) ($body['id'] ?? ''));
    $reason        = trim((string) ($body['reason'] ?? ''));
    $lines         = is_array($body['lines'] ?? null) ? $body['lines'] : [];

    if (!isValidUuidSaleVoid($transactionId)) {
        apiError('id inválido', 422);
    }
    if ($reason === '') {
        apiError('Falta el motivo de la anulación', 422);
    }

    $normalizedLines = [];
    foreach ($lines as $line) {
        $normalizedLines[] = [
            'itemSoldId' => isset($line['itemSoldId']) ? trim((string) $line['itemSoldId']) : null,
            'itemId'     => isset($line['itemId'])     ? trim((string) $line['itemId'])     : null,
            'restock'    => !empty($line['restock']),
        ];
    }

    $result = $svc->void($companyId, $transactionId, $userId, $reason, $normalizedLines, $registerId, $outletId);
    apiOk($result);
}

apiError('Método no soportado', 405);
