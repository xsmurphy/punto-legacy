<?php
/**
 * /api/v1/drawer.php — operaciones de caja/drawer del POS (Slice 26).
 *
 *   GET ?resource=check    → { isOpen: bool }  — ¿cajón abierto?
 *   GET                    → resumen completo   — list, date, subtotal, total, tips, returns
 *
 * Auth: JWT de tenant. Envelope canónico { ok, data }. Verbos REST (§22.7).
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/DrawerService.php';

$ctx        = apiAuthTenant();
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$registerId = $ctx['registerId'];

$svc      = new DrawerService();
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- GET ?resource=check: ¿cajón abierto? ---------------------------------
if ($method === 'GET' && $resource === 'check') {
    apiOk(['isOpen' => $svc->isOpen($registerId, $outletId, $companyId)]);
}

// --- GET: resumen completo del cajón --------------------------------------
if ($method === 'GET') {
    $data = $svc->getSummary($registerId, $outletId, $companyId);
    if ($data === null) {
        apiOk(['closed' => true]);
    }
    apiOk($data);
}

apiError('Operación no reconocida', 400);
