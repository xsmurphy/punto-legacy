<?php
/**
 * Auth del ADMIN REALM (/admin) — aislado del realm tenant.
 *
 * Realm separado criptográficamente del de tenants:
 *   - secret propio  : $_ENV['ADMIN_JWT_SECRET']  (≠ JWT_SECRET)
 *   - cookie propia  : _jwt_admin                 (≠ _jwt_panel)
 *   - audience       : claim `aud = "admin"`
 * Un `_jwt_panel` de tenant NUNCA valida acá (secret + aud distintos) y viceversa.
 *
 * Provee:
 *   adminVerifyPassword($email, $pass) → fila admin_user (CaseInsensitiveArray) | false
 *   adminIssueJwt($admin)              → string token (la cookie la setea el BFF, no la API)
 *   adminMiddleware()                  → valida _jwt_admin; define ADMIN_AUTHED_ID/EMAIL o corta 401
 *
 * Password con bcrypt (password_hash/password_verify) — NO el sha256+salt de `contact`.
 */

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/../../includes/jwt.php';

/** Verifica credenciales contra admin_user (activo). Devuelve la fila o false. */
function adminVerifyPassword(string $email, string $pass)
{
    global $db;
    $email = trim($email);
    if ($email === '' || $pass === '') {
        return false;
    }

    $r = $db->Execute(
        "SELECT adminId, email, name, passwordHash, status FROM admin_user WHERE lower(email) = lower(?) LIMIT 1",
        [$email]
    );
    if (!$r || $r->EOF) {
        return false;
    }
    $row = $r->fields;   // CaseInsensitiveArray
    if ((int) $row['status'] !== 1) {
        return false;
    }
    if (!password_verify($pass, (string) $row['passwordHash'])) {
        return false;
    }
    return $row;
}

/** Mintea el JWT del admin (sin setear cookie — la API es stateless; el BFF setea _jwt_admin). */
function adminIssueJwt($admin): string
{
    $secret = $_ENV['ADMIN_JWT_SECRET'] ?? '';
    if ($secret === '') {
        apiError('Admin auth no configurada (ADMIN_JWT_SECRET)', 500);
    }
    $ttl = (int) ($_ENV['ADMIN_JWT_TTL'] ?? 28800);
    $now = time();

    return jwtEncode([
        'iss'   => 'admin', // realm explícito; además del aud y del secret distinto (ADMIN_JWT_SECRET)
        'sub'   => (string) $admin['adminId'],
        'email' => (string) $admin['email'],
        'aud'   => 'admin',
        'iat'   => $now,
        'exp'   => $now + $ttl,
    ], $secret);
}

/** Extrae el token del realm admin (Bearer | cookie _jwt_admin | POST _jwt). */
function _adminExtractJwt(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return $m[1];
    }
    if (!empty($_COOKIE['_jwt_admin'])) {
        return $_COOKIE['_jwt_admin'];
    }
    if (!empty($_POST['_jwt'])) {
        return $_POST['_jwt'];
    }
    return null;
}

/**
 * Gate de los endpoints del admin realm. Valida el _jwt_admin con ADMIN_JWT_SECRET y exige
 * `aud === "admin"`. Define ADMIN_AUTHED_ID / ADMIN_AUTHED_EMAIL. Corta 401 si falla.
 */
function adminMiddleware(): void
{
    // Body JSON → $_POST (igual que apiMiddleware)
    if (empty($_POST)) {
        $body = file_get_contents('php://input');
        if ($body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $_POST = $decoded;
            }
        }
    }

    global $db;
    include_once __DIR__ . '/../../includes/db.php';

    $secret = $_ENV['ADMIN_JWT_SECRET'] ?? '';
    $token  = _adminExtractJwt();
    if ($secret === '' || $token === null) {
        apiUnauthorized('No autorizado (admin)');
    }

    $payload = jwtDecode($token, $secret);
    // Realm gate: aud=admin + iss=admin + sub presente. Tokens emitidos antes del
    // fix de iss son rechazados (admin usa ADMIN_JWT_SECRET distinto a JWT_SECRET,
    // así que cross-realm por construcción NO ocurre; el iss es defense-in-depth).
    if (!is_array($payload)
        || ($payload['iss'] ?? '') !== 'admin'
        || ($payload['aud'] ?? '') !== 'admin'
        || empty($payload['sub'])) {
        apiUnauthorized('No autorizado (admin)');
    }

    define('ADMIN_AUTHED_ID',    (string) $payload['sub']);
    define('ADMIN_AUTHED_EMAIL', (string) ($payload['email'] ?? ''));
}
