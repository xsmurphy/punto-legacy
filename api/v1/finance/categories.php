<?php
/**
 * REST — Categorías de Finanzas (plan de cuentas simplificado).
 *
 *   GET    /v1/finance/categories             → lista (auto-seed si el tenant no tiene ninguna)
 *   GET    /v1/finance/categories?id=<uuid>   → detalle
 *   POST   /v1/finance/categories             → crea
 *   PUT    /v1/finance/categories?id=<uuid>   → edita (solo nombre)
 *   DELETE /v1/finance/categories?id=<uuid>   → archiva (soft-delete; issystem no se puede)
 *
 * Auth realm `panel`. Requiere permiso `finance.manage`.
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$svc = new \Punto\Api\Finance\CategoryService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $row = $svc->find($id, (string) COMPANY_ID);
        if (!$row) {
            apiError('Categoría no encontrada', 404);
        }
        apiOk($row);
    }
    apiOk($svc->list((string) COMPANY_ID));
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
        $svc->archive($id, (string) COMPANY_ID);
    } catch (\RuntimeException $e) {
        apiError($e->getMessage(), 422);
    }
    apiOk(['id' => $id, 'status' => 0]);
}

apiError('Método no permitido', 405);
