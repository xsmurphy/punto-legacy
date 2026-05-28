<?php
/**
 * REST — Login del ADMIN REALM (público). POST { email, password }.
 *
 * Verifica contra admin_user (bcrypt) y mintea el JWT del realm admin (aud:"admin", ADMIN_JWT_SECRET).
 * NO setea cookie (la API es stateless) — devuelve el token y el BFF setea _jwt_admin HttpOnly.
 * Rate-limit por email+IP. NO usa apiMiddleware (ese es el gate del realm tenant).
 */

require_once __DIR__ . '/../../../includes/db.php';   // $db + .env
require_once __DIR__ . '/../../lib/admin_auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Método no permitido', 405);
}

// Body JSON → $_POST (acepta tanto form-urlencoded del BFF como JSON).
if (empty($_POST)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $_POST = $decoded; }
    }
}

$email = trim((string) ($_POST['email'] ?? ''));
$pass  = (string) ($_POST['password'] ?? '');

// Rate-limit básico (1ª capa) por email+IP. RateLimiter guarda contadores en $_SESSION → requiere
// session activa (este endpoint no pasa por apiMiddleware, que es quien la abre). Throttle robusto
// respaldado por store compartido (DB/Redis) keyed solo por email+IP queda para F6 (hardening): el
// approach por sesión no frena a un atacante scripteado que no porta la cookie de sesión.
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
include_once __DIR__ . '/../../../libraries/rateLimiter.php';
$rl = new RateLimiter('adminlogin:' . strtolower($email) . ':' . ($_SERVER['REMOTE_ADDR'] ?? ''));
try {
    $rl->limitRequestsInMinutes(10, 1);
} catch (RateExceededException $e) {
    apiError('Demasiados intentos, esperá un minuto', 429);
}

$admin = adminVerifyPassword($email, $pass);
if ($admin === false) {
    apiUnauthorized('Credenciales inválidas');   // mismo mensaje para email inexistente / pass errado
}

$token = adminIssueJwt($admin);

// Auditoría: marcar último login (no bloqueante).
$db->Execute("UPDATE admin_user SET lastLoginAt = now(), updated_at = now() WHERE adminId = ?", [(string) $admin['adminId']]);

apiOk([
    'token' => $token,
    'email' => (string) $admin['email'],
    'name'  => (string) $admin['name'],
]);
