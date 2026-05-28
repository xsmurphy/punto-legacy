<?php
/**
 * /api/v1/sync.php — checks de sincronización offline del POS (Slice 8).
 *
 *   POST op=deletedItems     { ids: [...] }  → cuáles itemIds ya no están activos
 *   POST op=deletedCustomers { ids: [...] }  → cuáles contactIds ya no están activos
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }.
 * data = lista de IDs borrados (puede ser []).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/SyncService.php';

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc = new SyncService();
$op  = (string) ($_POST['op'] ?? '');
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}

switch ($op) {
    case 'deletedItems':
        apiOk($svc->deletedItems($companyId, $ids));
        break;
    case 'deletedCustomers':
        apiOk($svc->deletedCustomers($companyId, $ids));
        break;
    default:
        apiError('Operación no reconocida', 400);
}
