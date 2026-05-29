<?php
/**
 * /api/v1/items.php — operaciones sobre ítems del catálogo (Slice 25).
 *
 *   GET ?id=<itemId>&resource=info   → detalles de un ítem + inventario por outlet
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/ItemService.php';

$ctx       = apiAuthTenant();
$companyId = $ctx['companyId'];

$svc      = new ItemService();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- GET ?resource=info: detalles de un ítem ------------------------------
if ($method === 'GET' && $resource === 'info') {
    $itemId = trim((string) dec($_GET['id'] ?? ''));
    if ($itemId === '') {
        apiError('Falta id', 422);
    }
    $data = $svc->getInfo($itemId, $companyId);
    if ($data === null) {
        apiError('Ítem no encontrado', 404);
    }
    apiOk($data);
}

apiError('Operación no reconocida', 400);
