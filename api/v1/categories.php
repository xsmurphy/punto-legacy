<?php
/**
 * REST canónico — Categorías de productos.
 *
 *   GET    /v1/categories              → lista del tenant
 *   GET    /v1/categories?id=<uuid>    → detalle
 *   POST   /v1/categories              → crea  (body: { name, parentId?, extra? })
 *   PUT    /v1/categories?id=<uuid>    → actualiza (partial)
 *   DELETE /v1/categories?id=<uuid>    → elimina
 *
 * Auth: panel (admin del catálogo) para escritura. GET también acepta el
 * realm `pos-app` — el bootstrap del POS (`/api/pos/bootstrap`) lo consume
 * para traer la lista propia de categorías (context/45, F1: el ítem ya no
 * lleva `categoryName` copiado, solo `categoryId`).
 *
 * Slice 1 del refactor taxonomy. Tabla `category` (migration 21).
 */

require_once __DIR__ . '/../bootstrap.php';

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctx       = apiAuthTenant($method === 'GET' ? ['panel', 'pos-app', 'api'] : ['panel']);
$companyId = $ctx['companyId'];
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\Categories\CategoryService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $cat = $svc->find($companyId, (string) $id);
            if ($cat === null) apiError('Categoría no encontrada', 404);
            apiOk($cat);
        }
        apiOk(['categories' => $svc->list($companyId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            $cat   = $svc->find($companyId, $newId);
            apiOk($cat, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $cat = $svc->find($companyId, (string) $id);
            apiOk($cat);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'DELETE':
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->delete($companyId, (string) $id);
            apiOk(['deleted' => true]);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    default:
        apiError('Method not allowed', 405);
}
