<?php
/**
 * /api/v1/order_items.php — manipulación de items dentro de transactionDetails (meta-JSONB).
 *
 *   POST ?resource=select  { items:[{orderId,itemId,oPosition}] }  → devuelve items que matchean
 *   PUT  ?resource=remove   { transId, itemId, oPosition[, autoPrint, motive] } → status=0 + WS
 *   PUT  ?resource=process  { items:[...] }                        → status=2 + devuelve afectados
 *   PUT  ?resource=move     { from, items:[...] }                  → status=2 (lee por name) + devuelve
 *
 * Auth: JWT de tenant. Verbos REST (§22.7). transactionDetails en meta (jsonb, §22.6).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/OrderItemsService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\OrderItemsService;

$ctx        = apiAuthTenant();
$companyId   = $ctx['companyId'];
$outletId    = $ctx['outletId'];
$registerId  = $ctx['registerId'];

$svc      = new OrderItemsService(TenantContext::fromAuth($ctx));
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

$asItems = static function (): array {
    $items = $_POST['items'] ?? [];
    return is_array($items) ? $items : [];
};

// --- select (READ, POST por payload) --------------------------------------
if ($method === 'POST' && $resource === 'select') {
    apiOk($svc->selectItems($companyId, $asItems()));
}

// --- remove (PUT) ---------------------------------------------------------
if ($method === 'PUT' && $resource === 'remove') {
    $transId   = trim((string) ($_POST['transId'] ?? ''));
    $itemId    = $_POST['itemId'] ?? '';
    $oPosition = $_POST['oPosition'] ?? null;
    if ($transId === '') {
        apiError('Falta transId', 422);
    }
    if (!$svc->removeItem($companyId, $transId, $itemId, $oPosition)) {
        apiError('No se pudo remover el item', 500);
    }

    try {
        updateLastTimeEdit($companyId, 'order');
        if (($_POST['autoPrint'] ?? '') === '1') {
            sendWS([
                'channel' => $outletId . '-register',
                'event'   => 'order',
                'message' => json_encode([
                    'ID'          => $transId,
                    'registerID'  => $registerId,
                    'autoPrint'   => true,
                    'motive'      => $_POST['motive'] ?? null,
                    'cancelledId' => $itemId,
                ]),
            ]);
        }
    } catch (\Throwable $e) {
        error_log('[order_items.remove] side-effect falló (ignorado): ' . $e->getMessage());
    }

    apiOk(['removed' => true]);
}

// --- process (PUT) --------------------------------------------------------
if ($method === 'PUT' && $resource === 'process') {
    $res = $svc->processUpdate($companyId, $asItems());
    if (!$res['ok']) {
        apiError('No se pudo procesar los items', 500);
    }
    updateLastTimeEdit($companyId, 'order');
    apiOk($res['items']);
}

// --- move (PUT) -----------------------------------------------------------
if ($method === 'PUT' && $resource === 'move') {
    $from = (string) ($_POST['from'] ?? '');
    $res  = $svc->moveItems($companyId, $from, $asItems());
    if (!$res['ok']) {
        apiError('No se pudo mover los items', 500);
    }
    updateLastTimeEdit($companyId, 'order');
    apiOk($res['items']);
}

apiError('Operación no reconocida', 400);
