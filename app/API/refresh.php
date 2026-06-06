<?php
/**
 * POST /API/refresh
 *
 * Renueva un JWT sin requerir credenciales.
 * El token actual debe ser válido (no expirado).
 * Útil para renovar antes de que expire durante un turno largo.
 *
 * Lee el token desde: Authorization header, cookie _jwt, o POST _jwt.
 *
 * Response 200: { "token": "eyJ...", "expires_in": 28800 }
 * Response 401: { "error": "Token inválido o expirado" }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/simple.config.php'; // carga .env
require_once __DIR__ . '/../includes/db.php';            // device check necesita $db
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../includes/jwt_middleware.php';

$secret = $_ENV['JWT_SECRET'] ?? '';

if (!$secret) {
    http_response_code(503);
    die(json_encode(['error' => 'JWT no configurado en servidor']));
}

$token = _jwtExtractToken();

if ($token === null) {
    http_response_code(401);
    die(json_encode(['error' => 'Token requerido']));
}

$payload = jwtDecode($token, $secret);

if ($payload === null) {
    http_response_code(401);
    die(json_encode(['error' => 'Token inválido o expirado']));
}

// Realm gate: refresh solo emite tokens POS desde tokens POS. Un token panel
// (mismo JWT_SECRET) NO debe poder refrescarse acá → privilege escalation.
if (($payload['iss'] ?? '') !== 'pos-app') {
    http_response_code(401);
    die(json_encode(['error' => 'Token de otro realm']));
}

// Device pairing — si el JWT trae `did`, validar status antes de emitir
// un token nuevo. Sin este check, un device revocado podría seguir
// emitiéndose tokens nuevos mientras tuviera uno vivo.
$deviceId = (string)($payload['did'] ?? '');
if ($deviceId !== '' && jwtIsDeviceRevoked($deviceId, (string)($payload['cid'] ?? ''))) {
    http_response_code(401);
    die(json_encode([
        'error' => 'Dispositivo desactivado por el administrador',
        'code'  => 'device_revoked',
    ]));
}

$ttl = (int)($_ENV['JWT_TTL'] ?? 28800);
$now = time();

$newPayload = [
    'iss'  => 'pos-app', // realm: separa tokens POS de panel/admin (mismo JWT_SECRET)
    'sub'  => $payload['sub'],
    'cid'  => $payload['cid'],
    'oid'  => $payload['oid'],
    'rid'  => $payload['rid'],
    'role' => $payload['role'],
    'iat'  => $now,
    'exp'  => $now + $ttl,
];
// Preservar did para que el refresh mantenga el emparejamiento
if ($deviceId !== '') {
    $newPayload['did'] = $deviceId;
}

$newToken = jwtEncode($newPayload, $secret);
jwtSetCookie($newToken, $ttl);

echo json_encode([
    'token'      => $newToken,
    'expires_in' => $ttl,
]);
