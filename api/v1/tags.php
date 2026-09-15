<?php
/**
 * REST canónico — Etiquetas de producto.
 *
 *   GET    /v1/tags              → lista del tenant
 *   GET    /v1/tags?id=<uuid>    → detalle
 *   POST   /v1/tags              → crea  (body: { name, extra? })
 *   PUT    /v1/tags?id=<uuid>    → actualiza (partial)
 *   DELETE /v1/tags?id=<uuid>    → elimina
 *
 * Auth: el GET acepta además `pos-app` — el diálogo "Etiquetas de la venta"
 * en /pos lista el catálogo para SUGERIR y que el cajero reuse en vez de
 * inventar variantes ("Whatsapp" / "venta whatsapp" / "WSP"), y lo hace con
 * el Bearer del device: el POS nunca manda el token del panel. Mismo criterio
 * y mismo precedente que /v1/price_list.php. Las MUTACIONES siguen siendo del
 * panel: administrar el catálogo no es tarea de la caja.
 *
 * Slice 4 del refactor taxonomy. Tabla `tag` (migration 39).
 */

require_once __DIR__ . '/../bootstrap.php';

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ctx       = apiAuthTenant($method === 'GET' ? ['panel', 'api', 'pos-app'] : ['panel', 'api']);
$companyId = $ctx['companyId'];
$id        = $_GET['id'] ?? null;

global $db;
$svc = new \Punto\Api\Tags\TagService($db);

switch ($method) {
    case 'GET':
        if ($id !== null) {
            $tag = $svc->find($companyId, (string) $id);
            if ($tag === null) apiError('Etiqueta no encontrada', 404);
            apiOk($tag);
        }
        apiOk(['tags' => $svc->list($companyId)]);
        break;

    case 'POST':
        try {
            $newId = $svc->create($companyId, $_POST);
            $tag = $svc->find($companyId, $newId);
            apiOk($tag, 201);
        } catch (\Throwable $e) {
            apiError($e->getMessage(), 422);
        }
        break;

    case 'PUT':
        if ($id === null) apiError('id requerido', 422);
        try {
            $svc->update($companyId, (string) $id, $_POST);
            $tag = $svc->find($companyId, (string) $id);
            apiOk($tag);
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
