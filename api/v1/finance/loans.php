<?php
/**
 * REST — Créditos de Finanzas (F2, context/30-cheques-prevision-creditos.md).
 * Total / cuotas iguales / primera fecha, frecuencia mensual, sin interés.
 *
 *   GET    /v1/finance/loans?status=&limit=&offset=            → lista
 *   GET    /v1/finance/loans?id=<uuid>                          → detalle (+ cuotas)
 *   POST   /v1/finance/loans { name, principal, installmentCount, firstDueDate } → crea
 *   PUT    /v1/finance/loans?id=<uuid>&resource=cancel          → anula
 *   PUT    /v1/finance/loans?installmentId=<uuid>&resource=pay   { accountId } → marca pagada
 *   PUT    /v1/finance/loans?installmentId=<uuid>&resource=unpay → revierte el pago
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$svc = new \Punto\Api\Finance\LoanService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = trim((string) ($_GET['resource'] ?? ''));

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $row = $svc->find($id, (string) COMPANY_ID);
        if (!$row) {
            apiError('Crédito no encontrado', 404);
        }
        apiOk($row);
    }

    $filters = [
        'status' => $_GET['status'] ?? null,
        'limit'  => $_GET['limit'] ?? null,
        'offset' => $_GET['offset'] ?? null,
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
    $userId   = (string) ($ctx['userId'] ?? '') ?: null;
    $outletId = (string) (defined('OUTLET_ID') ? OUTLET_ID : '') ?: null;
    $body     = is_array($_POST) ? $_POST : [];

    if ($resource === 'pay') {
        $installmentId = trim((string) ($_GET['installmentId'] ?? ''));
        if ($installmentId === '') {
            apiError('installmentId requerido', 400);
        }
        $accountId = trim((string) ($body['accountId'] ?? ''));
        if ($accountId === '') {
            apiError('accountId requerido', 400);
        }
        try {
            $row = $svc->payInstallment($installmentId, (string) COMPANY_ID, $accountId, $userId, $outletId);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk($row);
    }

    if ($resource === 'unpay') {
        $installmentId = trim((string) ($_GET['installmentId'] ?? ''));
        if ($installmentId === '') {
            apiError('installmentId requerido', 400);
        }
        try {
            $row = $svc->unpayInstallment($installmentId, (string) COMPANY_ID);
        } catch (\RuntimeException $e) {
            apiError($e->getMessage(), 422);
        }
        apiOk($row);
    }

    if ($resource === 'cancel') {
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

    apiError('resource inválido (esperado: pay, unpay o cancel)', 400);
}

apiError('Método no permitido', 405);
