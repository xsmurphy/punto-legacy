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

// MULTI-REALM (A7): la caja vive dentro del panel; acepta el realm panel
// además de pos-app.
$ctx        = apiAuthTenant(['panel', 'pos-app']);
$companyId  = $ctx['companyId'];
$registerId = $ctx['registerId'];
$outletId   = $ctx['outletId'];

$svc        = new RegisterService(TenantContext::fromAuth($ctx));
$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource   = (string) ($_GET['resource'] ?? '');

// GET ?resource=list = cajas activas de la sucursal activa (selector de caja
// del POS, A7). companyId + outletId SIEMPRE del JWT (no del request).
if ($method === 'GET' && $resource === 'list') {
    // P1 code-review: sin sucursal activa no listamos (evita query con
    // outletId='' que rompería el invariante de scope-por-outlet).
    if ($outletId === '') {
        apiError('Sin sucursal activa', 422);
    }
    $rs = ncmExecute(
        'SELECT registerId, registerName
           FROM register
          WHERE companyId = ? AND outletId = ? AND registerStatus = TRUE
          ORDER BY registerName ASC',
        [$companyId, $outletId],
        false,
        true
    );
    $registers = [];
    if ($rs && is_object($rs)) {
        while (!$rs->EOF) {
            $f = $rs->fields;
            $registers[] = [
                'id'   => (string) ($f['registerId'] ?? $f['registerid'] ?? ''),
                'name' => (string) ($f['registerName'] ?? $f['registername'] ?? ''),
            ];
            $rs->MoveNext();
        }
        $rs->Close();
    }
    apiOk(['registers' => $registers]);
}

// GET = numeración de documentos de la caja (docsNum). registerId del JWT.
if ($method === 'GET') {
    // P1 code-review: sin caja activa no hay numeración que devolver.
    if ($registerId === '') {
        apiError('Sin caja activa', 422);
    }
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
