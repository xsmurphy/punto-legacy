<?php
/**
 * POST /API/auth
 *
 * Endpoint REST de autenticación para el módulo /app.
 * Acepta credenciales de email/password y devuelve un JWT.
 * También establece el cookie _jwt para clientes browser.
 *
 * Request body (form o JSON):
 *   email    string  requerido
 *   password string  requerido
 *
 * Response 200:
 *   {
 *     "token":      "eyJ...",
 *     "expires_in": 28800,
 *     "companyId":  "wl",
 *     "outletId":   "wl",
 *     "registerId": "wl",
 *     "userId":     "wl"
 *   }
 *
 * Response 401: { "error": "Credenciales inválidas" }
 * Response 403: { "error": "Cuenta inhabilitada" }
 * Response 422: { "error": "Email y contraseña requeridos" }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/cors.php';

$rateLimiterId = $_SERVER['REMOTE_ADDR'];
require_once __DIR__ . '/../head.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../includes/jwt_middleware.php';

// Soportar body JSON y form-encoded
if (empty($_POST) && !empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $_POST = json_decode(file_get_contents('php://input'), true) ?? [];
}

$email = strtolower(trim(db_prepare($_POST['email'] ?? '')));
$pass  = db_prepare($_POST['password'] ?? '');

if (!$email || !$pass) {
    http_response_code(422);
    die(json_encode(['error' => 'Email y contraseña requeridos']));
}

$result = findEmailOrPhoneLogin($email);

if (!$result || passBuilder($pass, $result['salt']) !== $result['contactPassword']) {
    http_response_code(401);
    die(json_encode(['error' => 'Credenciales inválidas']));
}

$companyId = (int)$result['companyId'];
$userId    = (int)$result['contactId'];

if (!checkCompanyStatus($companyId)) {
    http_response_code(403);
    die(json_encode(['error' => 'Su cuenta está inhabilitada, por favor contáctenos']));
}

$outlet = ncmExecute(
    "SELECT outletId FROM outlet WHERE companyId = ? ORDER BY outletId ASC LIMIT 1",
    [$companyId]
);

if (!$outlet) {
    http_response_code(500);
    die(json_encode(['error' => 'No se encontró un outlet configurado']));
}

$outletId = (int)$outlet['outletId'];

$register = ncmExecute(
    "SELECT registerId FROM register WHERE outletId = ? ORDER BY registerId ASC LIMIT 1",
    [$outletId]
);

if (!$register) {
    http_response_code(500);
    die(json_encode(['error' => 'No se encontró una caja configurada']));
}

$registerId = (int)$register['registerId'];

$secret = $_ENV['JWT_SECRET'] ?? '';
$ttl    = (int)($_ENV['JWT_TTL'] ?? 28800);
$now    = time();

$payload = [
    'sub'  => $userId,
    'cid'  => $companyId,
    'oid'  => $outletId,
    'rid'  => $registerId,
    'role' => (int)$result['role'],
    'iat'  => $now,
    'exp'  => $now + $ttl,
];

$token = jwtEncode($payload, $secret);
jwtSetCookie($token, $ttl);

echo json_encode([
    'token'      => $token,
    'expires_in' => $ttl,
    'companyId'  => enc($companyId),
    'outletId'   => enc($outletId),
    'registerId' => enc($registerId),
    'userId'     => enc($userId),
]);
