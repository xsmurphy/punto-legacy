<?php
/**
 * /api/v1/customers.php — lecturas agregadas del cliente (desacople de /app).
 *
 *   GET ?resource=info&id=<customerId> → resumen para el modal de info (customerInfo)
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. `id` puede venir numérico o
 * encriptado (el Service lo normaliza). No-encontrado → data:[] (200), igual que el
 * legacy (echo json_encode([])) para que el front dispare su onFail.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/CustomerService.php';

$ctx       = apiAuthTenant();
$companyId = $ctx['companyId'];

$svc      = new CustomerService();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

if ($method === 'GET' && $resource === 'info') {
    $rawId = trim((string) ($_GET['id'] ?? ''));
    if ($rawId === '') {
        apiError('Falta id', 422);
    }
    apiOk($svc->getInfo($companyId, $rawId) ?? []);
}

if ($method === 'GET' && $resource === 'records') {
    $encId = trim((string) ($_GET['id'] ?? ''));
    if ($encId === '') {
        apiError('Falta id', 422);
    }
    apiOk($svc->getRecords($companyId, $encId));
}

// --- Recursos GRANULARES del cliente (piloto BFF-compone) ------------------
// Cada uno responde UN concepto reusable; el BFF (app/bff/customers.php) los
// compone en paralelo para armar el modal de info. `?resource=info` (arriba)
// queda como composite legacy / backward-compat.
$granular = [
    'profile'     => 'getProfile',      // null → 404 si el cliente no existe
    'recentItems' => 'getRecentItems',
    'debt'        => 'getDebt',
    'giftcards'   => 'getGiftCards',
    'address'     => 'getDefaultAddress',
];
if ($method === 'GET' && isset($granular[$resource])) {
    $rawId = trim((string) ($_GET['id'] ?? ''));
    if ($rawId === '') {
        apiError('Falta id', 422);
    }
    $data = $svc->{$granular[$resource]}($companyId, $rawId);
    if ($resource === 'profile' && $data === null) {
        apiError('Cliente no encontrado', 404);
    }
    apiOk($data);
}

apiError('Operación no reconocida', 400);
