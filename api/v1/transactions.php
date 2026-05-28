<?php
/**
 * /api/v1/transactions.php — operaciones sobre transacciones/órdenes (Slice 6).
 *
 *   POST op=delete           { transactionId }       → elimina la transacción
 *   POST op=deletePrintJob   { transactionId }       → elimina de la cola de impresión
 *   POST op=reject           { transactionId[, motive] } → rechaza orden (status 6)
 *   POST op=recordItemDeletion { itemId, motive }    → inserta en itemDeleted
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }.
 * Side-effects (sendWS, updateLastTimeEdit) van aquí, no en el Service.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/TransactionService.php';

$ctx        = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$userId     = $ctx['userId'];
$registerId = $ctx['registerId'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc = new TransactionService();
$op  = (string) ($_POST['op'] ?? '');

// --- delete ---------------------------------------------------------------
if ($op === 'delete') {
    $transactionId = trim((string) ($_POST['transactionId'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta transactionId', 422);
    }
    $ok = $svc->delete($transactionId, $companyId);
    if (!$ok) {
        apiError('No se pudo eliminar la transacción', 500);
    }
    apiOk(['deleted' => true]);
}

// --- deletePrintJob -------------------------------------------------------
if ($op === 'deletePrintJob') {
    $transactionId = trim((string) ($_POST['transactionId'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta transactionId', 422);
    }
    $ok = $svc->deletePrintJob($transactionId, $companyId);
    if (!$ok) {
        apiError('No se pudo eliminar el job de impresión', 500);
    }
    apiOk(['deleted' => true]);
}

// --- reject ---------------------------------------------------------------
if ($op === 'reject') {
    $transactionId = trim((string) ($_POST['transactionId'] ?? ''));
    if ($transactionId === '') {
        apiError('Falta transactionId', 422);
    }
    $motive = ($_POST['motive'] ?? '') ?: null;
    $ok     = $svc->reject($transactionId, $companyId, $motive);
    if (!$ok) {
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

// --- recordItemDeletion ---------------------------------------------------
if ($op === 'recordItemDeletion') {
    $itemId = trim((string) ($_POST['itemId'] ?? ''));
    if ($itemId === '') {
        apiError('Falta itemId', 422);
    }
    $motive = (string) ($_POST['motive'] ?? '');
    $ok     = $svc->recordItemDeletion($itemId, $motive, $userId, $companyId, $outletId);
    if (!$ok) {
        apiError('No se pudo registrar la eliminación', 500);
    }
    apiOk(['recorded' => true]);
}

apiError('Operación no reconocida', 400);
