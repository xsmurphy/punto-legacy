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

// Accept 'phone' (canónico) o 'email' (legacy back-compat para clients viejos)
$phone = strtolower(trim(db_prepare($_POST['phone'] ?? $_POST['email'] ?? '')));
$pass  = db_prepare($_POST['password'] ?? '');

if (!$phone || !$pass) {
    http_response_code(422);
    die(json_encode(['error' => 'Teléfono y contraseña requeridos']));
}

$result = findPhoneLogin($phone);

// contactPassword es CHAR(68) padded con espacios → rtrim antes de comparar.
// Mismo patrón que panel/API/auth.php tras el refactor JWT.
if (!$result || passBuilder($pass, $result['salt']) !== rtrim($result['contactPassword'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Credenciales inválidas']));
}

$companyId = (string)$result['companyId'];
$userId    = (string)$result['contactId'];

if (!checkCompanyStatus($companyId)) {
    http_response_code(403);
    die(json_encode(['error' => 'Su cuenta está inhabilitada, por favor contáctenos']));
}

// Si el contact tiene outlet asignado, usarlo; sino fallback al primer outlet activo.
// Mismo patrón que loginPart() en panel/includes/functions.php.
$contactOutletId = !empty($result['outletId']) ? (string)$result['outletId'] : null;

if ($contactOutletId) {
    $outletId = $contactOutletId;
} else {
    $outlet = ncmExecute(
        "SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1",
        [$companyId]
    );
    if (!$outlet) {
        http_response_code(500);
        die(json_encode(['error' => 'No se encontró un outlet configurado']));
    }
    $outletId = (string)$outlet['outletId'];
}

$register = ncmExecute(
    "SELECT registerId FROM register WHERE outletId = ? ORDER BY registerId ASC LIMIT 1",
    [$outletId]
);

if (!$register) {
    http_response_code(500);
    die(json_encode(['error' => 'No se encontró una caja configurada']));
}

$registerId = (string)$register['registerId'];

// Registrar device para el modelo device-pairing (§28).
require_once __DIR__ . '/../includes/device.php';
$deviceId = deviceRegister($companyId, $userId, $outletId, $registerId);

$secret = $_ENV['JWT_SECRET'] ?? '';
$ttl    = (int)($_ENV['JWT_TTL'] ?? 28800);
$now    = time();

$payload = [
    'iss'  => 'pos-app', // realm: separa tokens POS de panel/admin (mismo JWT_SECRET)
    'sub'  => $userId,
    'cid'  => $companyId,
    'oid'  => $outletId,
    'rid'  => $registerId,
    'role' => (int)$result['role'],
    'iat'  => $now,
    'exp'  => $now + $ttl,
];
if ($deviceId) {
    $payload['did'] = $deviceId;
}

$token = jwtEncode($payload, $secret);
jwtSetCookie($token, $ttl);

echo json_encode([
    'token'      => $token,
    'expires_in' => $ttl,
    'companyId'  => $companyId,
    'outletId'   => $outletId,
    'registerId' => $registerId,
    'userId'     => $userId,
]);
