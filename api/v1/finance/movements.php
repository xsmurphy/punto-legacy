<?php
/**
 * REST — Movimientos de Finanzas (el ledger de caja simple).
 *
 *   GET    /v1/finance/movements?accountId=&categoryId=&costCenterId=&kind=&from=&to=&q=&limit=&offset= → lista
 *   GET    /v1/finance/movements?id=<uuid>                                                → detalle
 *   POST   /v1/finance/movements                          { accountId, kind, amount, ... } → crea (manual)
 *   POST   /v1/finance/movements?resource=transfer         { fromAccountId, toAccountId, amount, ... } → transferencia
 *   PUT    /v1/finance/movements?id=<uuid>          { categoryId?, costCenterId? } → reclasifica
 *   DELETE /v1/finance/movements?id=<uuid>                 → anula (soft-void; solo manual/transfer)
 *
 * `categoryId`/`costCenterId` aceptan el valor literal `none` como FILTRO
 * (movimientos sin clasificar), no como id.
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel', 'api']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$svc = new \Punto\Api\Finance\MovementService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = trim((string) ($_GET['resource'] ?? ''));

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $row = $svc->find($id, (string) COMPANY_ID);
        if (!$row) {
            apiError('Movimiento no encontrado', 404);
        }
        apiOk($row);
    }

    $filters = [
        'accountId'    => $_GET['accountId'] ?? null,
        'categoryId'   => $_GET['categoryId'] ?? null,
        'costCenterId' => $_GET['costCenterId'] ?? null,
        'kind'       => $_GET['kind'] ?? null,
        'from'       => $_GET['from'] ?? null,
        'to'         => $_GET['to'] ?? null,
        'q'          => $_GET['q'] ?? null,
        'limit'      => $_GET['limit'] ?? null,
        'offset'     => $_GET['offset'] ?? null,
    ];
    apiOk($svc->list((string) COMPANY_ID, $filters));
}

if ($method === 'POST') {
    $body = is_array($_POST) ? $_POST : [];
    $body['userId'] = $body['userId'] ?? (string) ($ctx['userId'] ?? '');
    $body['outletId'] = $body['outletId'] ?? (string) (defined('OUTLET_ID') ? OUTLET_ID : '');

    if ($resource === 'transfer') {
        try {
            $result = $svc->transfer((string) COMPANY_ID, $body);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk($result);
    }

    try {
        $row = $svc->create((string) COMPANY_ID, $body);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($row);
}

// RECLASIFICAR: cambia categoría y/o centro de costo de un movimiento ya
// registrado, sin tocar monto/cuenta/kind (el saldo no se mueve). Es el camino
// por el que se clasifica el HISTÓRICO — incluidos los movimientos derivados
// (compras, gastos de caja del POS), que no se pueden anular desde acá pero sí
// reclasificar. Ver MovementService::reclassify().
if ($method === 'PUT') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id === '') {
        apiError('id requerido', 400);
    }
    $body = is_array($_POST) ? $_POST : [];
    try {
        $row = $svc->reclassify($id, (string) COMPANY_ID, $body);
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
        $result = $svc->void($id, (string) COMPANY_ID);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk($result);
}

apiError('Método no permitido', 405);
