<?php
/**
 * REST — Conciliación bancaria de Finanzas.
 *
 *   GET    /v1/finance/reconciliations?accountId=&status=&limit=&offset= → lista sesiones
 *   GET    /v1/finance/reconciliations?id=<uuid>                        → detalle (sesión + movimientos + diferencia)
 *   POST   /v1/finance/reconciliations   { accountId, statementDate, statementBalance } → crea sesión
 *   PUT    /v1/finance/reconciliations?id=<uuid>&resource=toggle { movementId, reconciled } → tilda/destilda
 *   POST   /v1/finance/reconciliations?id=<uuid>&resource=close { createAdjustment?, adjustmentCategoryId? } → cierra
 *   DELETE /v1/finance/reconciliations?id=<uuid>                       → cancela sesión abierta
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$svc = new \Punto\Api\Finance\ReconciliationService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = trim((string) ($_GET['resource'] ?? ''));
$userId = (string) ($ctx['userId'] ?? '') ?: null;

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        try {
            $row = $svc->detail($id, (string) COMPANY_ID);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 404);
        }
        apiOk($row);
    }

    $filters = [
        'accountId' => $_GET['accountId'] ?? null,
        'status'    => $_GET['status'] ?? null,
        'limit'     => $_GET['limit'] ?? null,
        'offset'    => $_GET['offset'] ?? null,
    ];
    apiOk($svc->list((string) COMPANY_ID, $filters));
}

if ($method === 'POST') {
    $body = is_array($_POST) ? $_POST : [];
    $id = trim((string) ($_GET['id'] ?? ''));

    if ($resource === 'close') {
        if ($id === '') {
            apiError('id requerido', 400);
        }
        $createAdjustment = !empty($body['createAdjustment']) && $body['createAdjustment'] !== 'false';
        $adjustmentCategoryId = (string) ($body['adjustmentCategoryId'] ?? '') ?: null;
        try {
            $row = $svc->close($id, (string) COMPANY_ID, $createAdjustment, $userId, $adjustmentCategoryId);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk($row);
    }

    try {
        $row = $svc->create((string) COMPANY_ID, $body, $userId);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($row);
}

if ($method === 'PUT') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        apiError('id requerido', 400);
    }
    $body = is_array($_POST) ? $_POST : [];

    if ($resource === 'toggle') {
        $movementId = trim((string) ($body['movementId'] ?? ''));
        if ($movementId === '') {
            apiError('movementId requerido', 400);
        }
        $reconciled = !empty($body['reconciled']) && $body['reconciled'] !== 'false';
        try {
            $row = $svc->toggleMovement($id, (string) COMPANY_ID, $movementId, $reconciled);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk($row);
    }

    apiError('resource inválido', 400);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        apiError('id requerido', 400);
    }
    try {
        $row = $svc->cancel($id, (string) COMPANY_ID);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($row);
}

apiError('Método no permitido', 405);
