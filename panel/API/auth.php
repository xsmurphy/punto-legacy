<?php
/**
 * Endpoint de autenticación del panel.
 *
 * POST /API/auth  → phone + password → emite JWT + sesión PHP + cookie _jwt_panel
 *                   (tenants login SOLO por teléfono; ver admin/login para super-admins SaaS)
 * GET  /API/auth  (legacy)  → valida api_key + company_id
 *
 * Respuesta canónica:
 *   { "ok": true, "data": { "token": "...", "expiresIn": 28800, ... } }
 *
 * La cookie `_jwt_panel` (HttpOnly) se emite automáticamente para uso browser.
 * Internamente delega a `loginPart()` para mantener el flow unificado con
 * `panel/login.php` (browser form) y `signUp()` (registro).
 */

session_start();

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/lib/response.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── Ruta nueva: POST con credenciales ────────────────────────────────────────
if ($method === 'POST' && !isset($_POST['api_key'])) {
    $body  = file_get_contents('php://input');
    $input = json_decode($body, true) ?: $_POST;

    // Accept 'phone' (canónico) o 'email' (legacy back-compat para clients viejos)
    $rawPhone = trim($input['phone'] ?? $input['email'] ?? '');
    $iso      = strtoupper(trim($input['iso'] ?? 'PY'));
    $pass     = $input['password'] ?? '';

    if (!$rawPhone || !$pass) {
        apiError('Debe completar todos los campos', 422);
    }

    // Bootstrap — loginPart() y helpers viven en functions.php
    include_once __DIR__ . '/../includes/db.php';
    include_once __DIR__ . '/../includes/simple.config.php';
    include_once __DIR__ . '/../includes/functions.php';

    if (!($_ENV['JWT_SECRET'] ?? '')) {
        apiError('JWT no configurado en el servidor', 501);
    }

    // Normalizar a E.164 con libphonenumber antes de buscar
    $phoneE164 = phoneToE164($rawPhone, $iso);
    if ($phoneE164 === null) {
        apiUnauthorized('Teléfono o contraseña incorrectos');
    }

    $result = findPhoneLogin(db_prepare($phoneE164));

    if (!$result) {
        apiUnauthorized('Teléfono o contraseña incorrectos');
    }

    if (checkForPassword(db_prepare($pass), $result['salt']) !== rtrim($result['contactPassword'])) {
        apiUnauthorized('Teléfono o contraseña incorrectos');
    }

    // Chequear status ANTES de loginPart() para evitar su dai() que rompería el envelope
    $company = ncmExecute(
        "SELECT status FROM company WHERE companyId = ? LIMIT 1",
        [$result['companyId']]
    );
    if (!$company || $company['status'] !== 'Active') {
        apiError('Su cuenta está inhabilitada, por favor contáctenos', 403);
    }

    // Setea $_SESSION + emite cookie _jwt_panel (vía issueJwtPanel interno)
    loginPart($result);

    $jwt = $GLOBALS['_last_jwt_panel'] ?? ['token' => null, 'expiresIn' => 0];

    apiOk([
        'token'     => $jwt['token'],
        'expiresIn' => $jwt['expiresIn'],
        'userId'    => (string)$result['contactId'],
        'companyId' => (string)$result['companyId'],
        'role'      => (int)$result['role'],
    ]);
}

// ── Ruta legacy: api_key + company_id ────────────────────────────────────────
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
apiOk(['success' => 'Conexión establecida']);
