<?php
/**
 * Endpoint de autenticación del panel.
 *
 * GET  /API/auth  (legacy)  → valida api_key + company_id (igual que antes)
 * POST /API/auth  (nuevo)   → email + password → emite JWT
 *
 * Respuesta JWT:
 *   { "ok": true, "data": { "token": "..." }, "meta": { ... } }
 *
 * La cookie _jwt_panel (HttpOnly) se emite automáticamente para uso browser.
 */

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/lib/response.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Ruta nueva: POST con credenciales ────────────────────────────────────────
if ($method === 'POST' && !isset($_POST['api_key'])) {
    $body  = file_get_contents('php://input');
    $input = json_decode($body, true) ?: $_POST;

    $email = strtolower(trim($input['email'] ?? ''));
    $pass  = $input['password'] ?? '';

    if (!$email || !$pass) {
        apiError('Debe completar todos los campos', 422);
    }

    // Bootstrap mínimo
    require_once __DIR__ . '/../libraries/whoops/autoload.php';
    include_once __DIR__ . '/../includes/db.php';
    include_once __DIR__ . '/../includes/simple.config.php';
    include_once __DIR__ . '/../libraries/hashid.php';
    include_once __DIR__ . '/../includes/functions.php';

    $jwtSecret = $_ENV['JWT_SECRET'] ?? '';
    if (!$jwtSecret) {
        apiError('JWT no configurado en el servidor', 501);
    }

    $result = findEmailOrPhoneLogin(db_prepare($email));

    if (!$result) {
        apiUnauthorized('Email o contraseña incorrectos');
    }

    $hash = checkForPassword(db_prepare($pass), $result['salt']);

    if ($hash !== $result['contactPassword']) {
        apiUnauthorized('Email o contraseña incorrectos');
    }

    // Verificar estado de la empresa
    $company = ncmExecute(
        "SELECT companyStatus FROM company WHERE companyId = ? LIMIT 1",
        [$result['companyId']]
    );
    if (!$company || $company['companyStatus'] !== 'Active') {
        apiError('Su cuenta está inhabilitada, por favor contáctenos', 403);
    }

    // Primer outlet activo
    $outlet = ncmExecute(
        "SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1",
        [$result['companyId']]
    );

    $ttl = (int)($_ENV['JWT_TTL'] ?? 28800);
    $now = time();

    $token = jwtEncode([
        'sub'  => (int)$result['contactId'],
        'cid'  => (int)$result['companyId'],
        'oid'  => (int)($outlet['outletId'] ?? 0),
        'rid'  => 0,
        'role' => (int)$result['role'],
        'iat'  => $now,
        'exp'  => $now + $ttl,
    ], $jwtSecret);

    // Cookie HttpOnly para browser
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('_jwt_panel', $token, [
        'expires'  => $now + $ttl,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $isHttps,
    ]);

    $hashids = new Hashids\Hashids(SALT);
    apiOk([
        'token'     => $token,
        'expiresIn' => $ttl,
        'userId'    => $hashids->encode((int)$result['contactId']),
        'companyId' => $hashids->encode((int)$result['companyId']),
        'role'      => (int)$result['role'],
    ]);
}

// ── Ruta legacy: api_key + company_id ────────────────────────────────────────
include_once 'api_head.php';
jsonDieResult(['success' => 'Conexión establecida'], 200);
