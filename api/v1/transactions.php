<?php
/**
 * /api/v1/transactions.php — operaciones sobre transacciones/órdenes (Slice 6).
 *
 *   DELETE ?id=<txId>                      → elimina la transacción
 *   DELETE ?id=<txId>&resource=printjob    → elimina de la cola de impresión
 *   PUT    ?id=<txId>&resource=reject { motive } → rechaza orden (status 6) + WS
 *   POST   ?resource=itemDeletion { itemId, motive } → inserta en itemDeleted
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 * Side-effects (sendWS, updateLastTimeEdit) van aquí, no en el Service.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/TransactionService.php';

$ctx        = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$userId     = $ctx['userId'];
$registerId = $ctx['registerId'];

$svc      = new TransactionService();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- DELETE: eliminar transacción o job de impresión ----------------------
if ($method === 'DELETE') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta id', 422);
    }
    if ($resource === 'printjob') {
        if (!$svc->deletePrintJob($transactionId, $companyId)) {
            apiError('No se pudo eliminar el job de impresión', 500);
        }
        apiOk(['deleted' => true]);
    }
    if (!$svc->delete($transactionId, $companyId)) {
        apiError('No se pudo eliminar la transacción', 500);
    }
    apiOk(['deleted' => true]);
}

// --- PUT ?resource=reject: rechazar orden (transición de estado) -----------
if ($method === 'PUT' && $resource === 'reject') {
    $transactionId = trim((string) ($_GET['id'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta id', 422);
    }
    $motive = ($_POST['motive'] ?? '') ?: null;
    if (!$svc->reject($transactionId, $companyId, $motive)) {
        apiError('No se pudo rechazar la orden', 500);
    }

    // Side-effects best-effort: fallar aquí no revierte el UPDATE.
    try {
        updateLastTimeEdit($companyId, 'order');
        sendWS([
            'channel' => $outletId . '-register',
            'event'   => 'order',
            'message' => json_encode(['ID' => $transactionId, 'registerID' => $registerId]),
        ]);
    } catch (\Throwable $e) {
        error_log('[transactions.reject] side-effect falló (ignorado): ' . $e->getMessage());
    }

    apiOk(['rejected' => true]);
}

// --- POST ?resource=itemDeletion: registrar borrado de ítem ---------------
if ($method === 'POST' && $resource === 'itemDeletion') {
    $itemId = trim((string) ($_POST['itemId'] ?? ''));
    if ($itemId === '') {
        apiError('Falta itemId', 422);
    }
    $motive = (string) ($_POST['motive'] ?? '');
    if (!$svc->recordItemDeletion($itemId, $motive, $userId, $companyId, $outletId)) {
        apiError('No se pudo registrar la eliminación', 500);
    }
    apiOk(['recorded' => true]);
}

apiError('Operación no reconocida', 400);
