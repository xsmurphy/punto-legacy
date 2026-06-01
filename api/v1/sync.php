<?php
/**
 * /api/v1/sync.php — checks de sincronización offline del POS (Slice 8).
 *
 *   POST ?resource=items     { ids: [...] }  → cuáles itemIds ya no están activos
 *   POST ?resource=customers { ids: [...] }  → cuáles contactIds ya no están activos
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }.
 * data = lista de IDs borrados (puede ser []).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/SyncService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\SyncService;

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

// POST porque es una lectura con payload grande (lista de IDs). El sub-recurso (items|
// customers) se distingue por ?resource=. Ver §22.7.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc      = new SyncService(TenantContext::fromAuth($ctx));
$resource = (string) ($_GET['resource'] ?? '');
$ids      = $_POST['ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}

switch ($resource) {
    case 'items':
        apiOk($svc->deletedItems($companyId, $ids));
        break;
    case 'customers':
        apiOk($svc->deletedCustomers($companyId, $ids));
        break;
    default:
        apiError('Sub-recurso no soportado (items|customers)', 400);
}
