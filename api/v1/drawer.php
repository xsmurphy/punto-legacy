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
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\DrawerService;

$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$outletId   = $ctx['outletId'];
$registerId = $ctx['registerId'];

$svc      = new DrawerService(TenantContext::fromAuth($ctx));
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');

// --- GET ?resource=check: ¿cajón abierto? ---------------------------------
if ($method === 'GET' && $resource === 'check') {
    apiOk(['isOpen' => $svc->isOpen($registerId, $outletId, $companyId)]);
}

// --- Recursos GRANULARES del cierre de caja (patrón BFF-compone) -----------
// `open` da la fila del drawer abierto; los demás filtran por `since` (la fecha
// de apertura). registerId/outletId/companyId SIEMPRE salen del JWT (scoping de
// tenant garantizado); `since` es un parámetro de cliente → estos recursos son
// "extracciones/ingresos/ventas DESDE una fecha" (reusables por reportes). El
// rollup CONFIABLE del cierre es el que compone el BFF derivando `since` de
// `open` (no un granular suelto con un `since` arbitrario).
if ($method === 'GET' && $resource === 'open') {
    $open = $svc->getOpen($registerId, $outletId, $companyId);
    if ($open === null) {
        apiOk(['closed' => true]);
    }
    apiOk($open);
}
if ($method === 'GET' && in_array($resource, ['expenses', 'income', 'salesByPayment'], true)) {
    $since = trim((string) ($_GET['since'] ?? ''));
    if ($since === '') {
        apiError('Falta since', 422);
    }
    $data = match ($resource) {
        'expenses'       => $svc->getExpenses($registerId, $since),
        'income'         => $svc->getIncome($registerId, $since),
        'salesByPayment' => ['payments' => $svc->getPaymentBreakdown($registerId, $since)],
    };
    apiOk($data);
}

// --- GET: resumen completo del cajón (composite legacy/backward-compat) ----
if ($method === 'GET') {
    $data = $svc->getSummary($registerId, $outletId, $companyId);
    if ($data === null) {
        apiOk(['closed' => true]);
    }
    apiOk($data);
}

apiError('Operación no reconocida', 400);
