<?php
/**
 * /api/v1/attendance.php — fichaje de asistencia (Slice 13, cluster ENCOM→Punto).
 *
 *   POST { outletId, userId, token }  → abre/cierra turno (toggle)
 *
 * companyId SIEMPRE del JWT. El `token` es el QR del outlet = md5(companyId . outletId);
 * se verifica para garantizar presencia física (mismo check que panel/API/set_attendance).
 * Respuesta: { ok, data:{ error, type } }. El BFF desenvuelve data al top level.
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/../lib/services/AttendanceService.php';
use Punto\Api\Context\TenantContext;
use Punto\Api\Services\AttendanceService;

$ctx       = apiAuthTenant();
$companyId  = $ctx['companyId'];

// POST = registrar un fichaje (crea o cierra un turno). Verbos REST (§22.7).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$outletId = trim((string) ($_POST['outletId'] ?? ''));
$userId   = trim((string) ($_POST['userId'] ?? ''));
$token    = (string) ($_POST['token'] ?? '');

if ($outletId === '' || $userId === '' || $token === '') {
    apiError('Faltan campos requeridos (outletId, userId, token)', 422);
}

// Integridad del QR: el token escaneado debe coincidir con md5(companyId . outletId).
$expected = md5($companyId . $outletId);
if (!hash_equals($expected, $token)) {
    apiError('Código incorrecto', 403);
}

$svc = new AttendanceService(TenantContext::fromAuth($ctx));
$res = $svc->toggle($companyId, $outletId, $userId);

apiOk(['error' => $res['error'], 'type' => $res['type']]);
