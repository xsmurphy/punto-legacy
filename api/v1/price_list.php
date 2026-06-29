<?php
/**
 * /api/v1/price_list.php — Listas de precios (panel + POS).
 *
 *   GET    (sin ?id)           → lista todas las listas del tenant
 *   GET    ?id=<uuid>          → detalle de una lista con sus ítems
 *   POST   { name, defaultAdjustment, validFrom?, validTo?, status }  → crea
 *   PUT    ?id=<uuid> { ... }  → actualiza encabezado
 *   DELETE ?id=<uuid>          → elimina lista y sus ítems (CASCADE)
 *
 * Auth: realm panel (frontend) — las listas son admin, no el cajero.
 * El resolve de precio en la caja usa /v1/price_resolve.php.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/PriceListService.php';

use Punto\Api\Context\TenantContext;
use Punto\Api\Services\PriceListService;

$method    = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id        = trim((string) ($_GET['id'] ?? ''));

// GET acepta panel + pos-app (el dialog "Lista de precios" en /pos lee listas
// con el Bearer del device, ver memoria: el POS es módulo separado y nunca
// usa el JWT panel). Mutaciones (POST/PUT/DELETE) siguen exclusivas del panel.
$realms    = $method === 'GET' ? ['panel', 'pos-app'] : ['panel'];
$ctx       = apiAuthTenant($realms);
$companyId = $ctx['companyId'];

$svc = new PriceListService(TenantContext::fromAuth($ctx));

switch ($method) {
    case 'GET':
        if ($id !== '') {
            $data = $svc->get($companyId, $id);
            if ($data === null) {
                apiError('Lista no encontrada', 404);
            }
            apiOk($data);
        }
        apiOk($svc->list($companyId));

    case 'POST':
        $res = $svc->create($companyId, [
            'name'              => $_POST['name'] ?? '',
            'defaultAdjustment' => $_POST['defaultAdjustment'] ?? 0,
            'validFrom'         => $_POST['validFrom'] ?? null,
            'validTo'           => $_POST['validTo'] ?? null,
            'status'            => $_POST['status'] ?? true,
        ]);
        if (empty($res['ok'])) {
            apiError($res['error'] ?? 'No se pudo crear la lista', 500);
        }
        apiOk($res['data']);

    case 'PUT':
        if ($id === '') {
            apiError('Falta id', 422);
        }
        $res = $svc->update($companyId, $id, [
            'name'              => $_POST['name']              ?? null,
            'defaultAdjustment' => $_POST['defaultAdjustment'] ?? null,
            'validFrom'         => array_key_exists('validFrom', $_POST) ? $_POST['validFrom'] : '__absent__',
            'validTo'           => array_key_exists('validTo', $_POST)   ? $_POST['validTo']   : '__absent__',
            'status'            => $_POST['status']            ?? null,
        ]);
        if (empty($res['ok'])) {
            apiError('No se pudo actualizar la lista', 500);
        }
        apiOk($res['data']);

    case 'DELETE':
        if ($id === '') {
            apiError('Falta id', 422);
        }
        $res = $svc->delete($companyId, $id);
        if (empty($res['ok'])) {
            apiError('No se pudo eliminar la lista', 500);
        }
        apiOk(['deleted' => true, 'id' => $id]);

    default:
        apiError('Método no permitido', 405);
}
