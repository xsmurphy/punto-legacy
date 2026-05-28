<?php
/**
 * /api/v1/register.php — sesión de caja (register) del POS (Slice 10).
 *
 *   POST op=setSession { sessionId }  → fija el sessionId de la caja + broadcast WS
 *
 * registerId/companyId SIEMPRE del JWT (nunca del request). Envelope canónico { ok, data }.
 * El broadcast WS es best-effort: su falla no revierte la persistencia.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/RegisterService.php';

$ctx        = apiAuthTenant();
$companyId  = $ctx['companyId'];
$registerId = $ctx['registerId'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$svc = new RegisterService();
$op  = (string) ($_POST['op'] ?? '');

if ($op === 'setSession') {
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
}

apiError('Operación no reconocida', 400);
