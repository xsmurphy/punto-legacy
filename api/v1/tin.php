<?php
/**
 * /api/v1/tin.php — búsqueda de RUC paraguayo.
 *
 *   GET ?id=<ruc>&country=PY  → { ok:true, data: { id, tin, name, fullName, address, phone } }
 *                              404 si no se encontró
 *                              422 si falta id o country != PY
 *
 * Auth: JWT de tenant. No toca BD (proxy a Marangatu via TinService).
 * Strangler de `app/load.php?load=tin` y `panel/API/get_tin.php`.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/TinService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\TinService;

$ctx = apiAuthTenant();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiError('Método no permitido', 405);
}

$id      = trim((string) ($_GET['id']      ?? ''));
$country = trim((string) ($_GET['country'] ?? ''));

if ($id === '' || $country === '') {
    apiError('id y country son obligatorios', 422);
}

$svc = new TinService(TenantContext::fromAuth($ctx));

try {
    $result = $svc->lookup($id, $country);
} catch (\InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
}

if ($result === null) {
    apiError('No se encontraron registros', 404);
}

apiOk($result);
