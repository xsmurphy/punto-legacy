<?php
/**
 * REST — Cheques de Finanzas (emitidos/recibidos).
 *
 *   GET    /v1/finance/checks?direction=&status=&from=&to=&limit=&offset= → lista
 *   GET    /v1/finance/checks?id=<uuid>                                  → detalle
 *   POST   /v1/finance/checks                    { direction, amount, ... } → crea
 *   PUT    /v1/finance/checks?id=<uuid>          { ...campos }            → edita
 *   PUT    /v1/finance/checks?id=<uuid>&resource=status { status }        → cambia estado
 *   DELETE /v1/finance/checks?id=<uuid>                                  → anula (status=cancelled)
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$svc = new \Punto\Api\Finance\CheckService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = trim((string) ($_GET['resource'] ?? ''));

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $row = $svc->find($id, (string) COMPANY_ID);
        if (!$row) {
            apiError('Cheque no encontrado', 404);
        }
        apiOk($row);
    }

    $filters = [
        'direction' => $_GET['direction'] ?? null,
        'status'    => $_GET['status'] ?? null,
        'from'      => $_GET['from'] ?? null,
        'to'        => $_GET['to'] ?? null,
        'limit'     => $_GET['limit'] ?? null,
        'offset'    => $_GET['offset'] ?? null,
    ];
    apiOk($svc->list((string) COMPANY_ID, $filters));
}

if ($method === 'POST') {
    $body = is_array($_POST) ? $_POST : [];
    try {
        $row = $svc->create((string) COMPANY_ID, $body);
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
    $userId   = (string) ($ctx['userId'] ?? '') ?: null;
    $outletId = (string) (defined('OUTLET_ID') ? OUTLET_ID : '') ?: null;

    if ($resource === 'status') {
        $status = trim((string) ($body['status'] ?? ''));
        if ($status === '') {
            apiError('status requerido', 400);
        }
        try {
            $row = $svc->changeStatus($id, (string) COMPANY_ID, $status, $userId, $outletId);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk($row);
    }

    try {
        $row = $svc->update($id, (string) COMPANY_ID, $body);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($row);
}

if ($method === 'DELETE') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        apiError('id requerido', 400);
    }
    try {
        $row = $svc->delete($id, (string) COMPANY_ID);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($row);
}

apiError('Método no permitido', 405);
