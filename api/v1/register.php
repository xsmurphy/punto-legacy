<?php
/**
 * /api/v1/register.php — sesión de caja (register) del POS (Slice 10).
 *
 *   GET                → numeración de documentos de la caja (docsNum)
 *   PUT { sessionId }  → fija el sessionId de la caja (registerId del JWT) + broadcast WS
 *
 * registerId/companyId SIEMPRE del JWT (nunca del request). Envelope canónico { ok, data }.
 * El broadcast WS es best-effort: su falla no revierte la persistencia.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/RegisterService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\RegisterService;

$ctx        = apiAuthTenant();
$companyId  = $ctx['companyId'];
$registerId = $ctx['registerId'];

$svc        = new RegisterService(TenantContext::fromAuth($ctx));
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET = numeración de documentos de la caja (docsNum). registerId del JWT.
if ($method === 'GET') {
    apiOk($svc->docNumbers($registerId, $companyId));
}

// PUT = actualizar el estado de la caja (sessionId). registerId del JWT. Verbos REST (§22.7).
if ($method !== 'PUT') {
    apiError('Método no permitido', 405);
}

$sessionId = (int) ($_POST['sessionId'] ?? 0);
if ($sessionId <= 0) {
    apiError('Falta sessionId', 422);
}

$ok = $svc->setSession($registerId, $companyId, $sessionId);
if (!$ok) {
    apiError('No se pudo fijar la sesión de caja', 500);
}

// Broadcast WS best-effort: avisa a otras pestañas de la MISMA caja que su sesión
// quedó obsoleta. Canal: <companyId>-<registerId>-registerSession (igual que el front).
try {
    sendWS([
        'channel' => $companyId . '-' . $registerId . '-registerSession',
        'event'   => 'checkSession',
        'message' => $sessionId,
    ]);
} catch (\Throwable $e) {
    error_log('[register.setSession] WS broadcast falló (ignorado): ' . $e->getMessage());
}

apiOk(['sessionId' => $sessionId]);
