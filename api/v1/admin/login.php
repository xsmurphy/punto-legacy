<?php
/**
 * REST — Login del ADMIN REALM (público). POST { email, password }.
 *
 * Verifica contra admin_user (bcrypt) y crea la sesion opaca del realm admin.
 * NO setea cookie (la API es stateless) — devuelve el token y el BFF setea _jwt_admin HttpOnly.
 * Rate-limit por email+IP. NO usa apiMiddleware (ese es el gate del realm tenant).
 */

require_once __DIR__ . '/../../includes/db.php';   // $db + .env
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';

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

// Rate-limit por email + IP real del cliente, con contadores en Redis
// (lib/RateLimit/RateLimiter.php). Antes vivían en $_SESSION, lo que lo hacía
// decorativo: sin cookie cada request estrenaba sesión, o sea contador en 0, así
// que el atacante scripteado —el único que importa acá— nunca era frenado.
//
// FAIL-CLOSED (a diferencia del límite global de head.php, que es fail-open):
// este throttle es lo ÚNICO que se interpone entre internet y un chequeo bcrypt
// sin autenticar contra la tabla de superusuarios de la plataforma. Si Redis no
// responde no podemos contar intentos, y fail-open dejaría credential stuffing
// ilimitado contra /admin justo durante una caída (que un atacante puede
// provocar o simplemente esperar). El costo de fail-closed es que el login de
// /admin no anda mientras Redis esté caído: es una consola interna de un puñado
// de usuarios, y el tráfico de tenants no se ve afectado. Indisponibilidad
// acotada y breve contra exposición de credenciales sin techo — cierra.
require_once __DIR__ . '/../../lib/RateLimit/RateLimiter.php';
require_once __DIR__ . '/../../lib/Http/ClientIp.php';
require_once __DIR__ . '/../../lib/Cache/RedisClient.php';

use Punto\Api\Http\ClientIp;
use Punto\Api\RateLimit\RateExceededException;
use Punto\Api\RateLimit\RateLimiter;
use Punto\Api\RateLimit\RateLimiterUnavailableException;

$rl = new RateLimiter(strtolower($email) . '|' . ClientIp::resolve(), 'adminlogin');
try {
    $rl->limit(10, 60, RateLimiter::FAIL_CLOSED);
} catch (RateExceededException $e) {
    apiError('Demasiados intentos, esperá un minuto', 429);
} catch (RateLimiterUnavailableException $e) {
    apiError('Servicio no disponible temporalmente, reintentá en unos minutos', 503);
}

$admin = adminVerifyPassword($email, $pass);
if ($admin === false) {
    apiUnauthorized('Credenciales inválidas');   // mismo mensaje para email inexistente / pass errado
}

$token = adminIssueSession($admin);

// Auditoría: marcar último login (no bloqueante).
$db->Execute("UPDATE admin_user SET lastLoginAt = now(), updated_at = now() WHERE adminId = ?", [(string) $admin['adminId']]);

apiOk([
    'token' => $token,
    'email' => (string) $admin['email'],
    'name'  => (string) $admin['name'],
]);
