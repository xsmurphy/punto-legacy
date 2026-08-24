<?php
/**
 * REST — Centros de costo de Finanzas (`fin_cost_center`, mig 167).
 *
 *   GET    /v1/finance/cost-centers            → lista (solo activos)
 *   GET    /v1/finance/cost-centers?id=<uuid>  → detalle
 *   POST   /v1/finance/cost-centers            → crea       { name, code?, sortOrder? }
 *   PUT    /v1/finance/cost-centers?id=<uuid>  → edita      { name, code?, sortOrder? }
 *   DELETE /v1/finance/cost-centers?id=<uuid>  → archiva (soft-delete)
 *
 * Sin auto-seed (a diferencia de /v1/finance/categories): no hay centros de
 * costo por defecto: la lista arranca vacía y la carga el comercio.
 *
 * Auth realm `panel`. Requiere permiso `finance.manage` — el mismo que gatea
 * el resto del módulo (categorías, movimientos, reportes, cuentas).
 */
require_once __DIR__ . '/../../bootstrap.php';

$ctx = apiAuthTenant(['panel']);
if (!hasPermission('finance.manage')) {
    apiError('No tenés permiso para gestionar Finanzas (requiere: finance.manage)', 403);
}

$svc = new \Punto\Api\Finance\CostCenterService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $id = trim((string) ($_GET['id'] ?? ''));
    if ($id !== '') {
        $row = $svc->find($id, (string) COMPANY_ID);
        if (!$row) {
            apiError('Centro de costo no encontrado', 404);
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
