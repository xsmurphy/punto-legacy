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

// id viene cifrado (enc/dec) en todos los recursos de ítem.
$itemId = trim((string) dec($_GET['id'] ?? ''));

// --- Recursos GRANULARES (patrón BFF-compone, §22.12) ---------------------
// `core` = campos del ítem; `inventory` = stock por outlet (sólo si trackea).
// El BFF (app/bff/items.php) pide ambos en paralelo y mergea. companyId sale
// del JWT (scoping de tenant).
if ($method === 'GET' && $resource === 'core') {
    if ($itemId === '') {
        apiError('Falta id', 422);
    }
    $data = $svc->getCore($itemId, $companyId);
    if ($data === null) {
        apiError('Ítem no encontrado', 404);
    }
    apiOk($data);
}
if ($method === 'GET' && $resource === 'inventory') {
    if ($itemId === '') {
        apiError('Falta id', 422);
    }
    // Devuelve `[]` si el ítem no trackea / no existe — la clave `inventory` del front.
    apiOk(['inventory' => $svc->getInventory($itemId, $companyId)]);
}

// --- GET ?resource=info: detalles completos (composite legacy/backward-compat) ---
if ($method === 'GET' && $resource === 'info') {
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
