<?php
/**
 * /API/v1/customer_note.php — notas de cliente del POS (slice 5 del desacople de /app).
 *
 * Reemplaza el handler customerNote del monolito action.php. JWT-gated; companyId del token.
 *
 *   POST op=add { customerId, text }
 *
 * Envelope canónico { ok, data } / { ok:false, error }.
 */

session_start();

$appDir = dirname(__DIR__, 2);
chdir($appDir);

require_once $appDir . '/includes/cors.php';
require_once $appDir . '/includes/jwt_middleware.php';
require_once __DIR__ . '/../lib/response.php';

$rateLimiterId = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
require_once $appDir . '/head.php';

if (!jwtAuthenticate()) {
    apiError('Autenticación requerida', 401);
}

$companyId  = AUTHED_COMPANY_ID;
$outletId   = AUTHED_OUTLET_ID;
$userId     = AUTHED_USER_ID;
$registerId = AUTHED_REGISTER_ID;
$roleId     = AUTHED_ROLE_ID;

if (!checkCompanyStatus($companyId)) {
    apiError('Company Blocked', 403);
}

require_once $appDir . '/data.php';
require_once $appDir . '/lib/CustomerNoteService.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

$op         = (string) ($_POST['op'] ?? '');
$customerId = trim((string) ($_POST['customerId'] ?? ''));
$text       = (string) ($_POST['text'] ?? '');

if ($op !== 'add') {
    apiError('Operación no soportada', 400);
}
if ($customerId === '' || trim($text) === '') {
    apiError('Faltan customerId/text', 422);
}

$res = (new CustomerNoteService())->add($companyId, $customerId, $text);
if (empty($res['ok'])) {
    apiError('No se pudo guardar la nota', 500);
}
apiOk($res);
