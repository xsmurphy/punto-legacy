<?php
/**
 * REST canónico — Marcas de producto.
 *
 *   GET    /v1/brands              → lista del tenant
 *   GET    /v1/brands?id=<uuid>    → detalle
 *   POST   /v1/brands              → crea  (body: { name, extra? })
 *   PUT    /v1/brands?id=<uuid>    → actualiza (partial)
 *   DELETE /v1/brands?id=<uuid>    → elimina
 *
 * Auth: panel (admin del catálogo) para escritura. GET también acepta el
 * realm `pos-app` — el bootstrap del POS (`/api/pos/bootstrap`) lo consume
 * para traer la lista propia de marcas (context/45, F1: el ítem ya no lleva
 * `brandName` copiado, solo `brandId`).
 *
 * Slice 2 del refactor taxonomy. Tabla `brand` (migration 22).
 */

require_once __DIR__ . '/../bootstrap.php';

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctx       = apiAuthTenant($method === 'GET' ? ['panel', 'pos-app', 'api'] : ['panel']);
$companyId = $ctx['companyId'];
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\Brands\BrandService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $brand = $svc->find($companyId, (string) $id);
            if ($brand === null) apiError('Marca no encontrada', 404);
            apiOk($brand);
        }
        apiOk(['brands' => $svc->list($companyId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            $brand = $svc->find($companyId, $newId);
            apiOk($brand, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $brand = $svc->find($companyId, (string) $id);
            apiOk($brand);
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
