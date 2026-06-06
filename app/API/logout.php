<?php
/**
 * POST /API/logout
 *
 * "Desinstalar Punto de este dispositivo" — usuario disparado desde la UI
 * del POS. NO requiere CSRF token (usa la cookie + valida JWT) porque la
 * pérdida del recurso es voluntaria por el usuario y solo afecta a su
 * propio device.
 *
 * Comportamiento:
 *   1. Lee el JWT (cookie / Authorization / POST).
 *   2. Si trae `did`, marca `device.status = 0, revokedAt = now(),
 *      revokedBy = <el propio userId>` para que el JWT quede inservible
 *      aunque alguien intente reusarlo.
 *   3. Invalida el cache file para que el efecto sea inmediato.
 *   4. Setea cookie `_jwt` con expires=0 → el browser la borra.
 *
 * Response 200: { "ok": true } — incluso si no había token, para no leakar
 * detalles del estado. El front siempre limpia localStorage y reloadea.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/cors.php';
require_once __DIR__ . '/../includes/simple.config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../includes/jwt_middleware.php';

// Solo POST — evita CSRF accidental por <img src="/API/logout"> u otros
// hot-links que pudieran disparar GET.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Método no permitido']));
}

$secret = $_ENV['JWT_SECRET'] ?? '';
$token  = $secret ? _jwtExtractToken() : null;

if ($token !== null && $secret) {
    $payload  = jwtDecode($token, $secret);
    $isPosToken = is_array($payload) && (($payload['iss'] ?? '') === 'pos-app');

    if ($isPosToken) {
        $deviceId  = (string)($payload['did'] ?? '');
        $companyId = (string)($payload['cid'] ?? '');
        $userId    = (string)($payload['sub'] ?? '');

        if ($deviceId !== '' && $companyId !== ''
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyId)
        ) {
            try {
                // Doble guard tenant: revoke solo si companyId del JWT matchea
                // el de la row (defense-in-depth contra forjado del did).
                global $db;
                $db->Execute(
                    "UPDATE device SET status = 0, revokedAt = now(), revokedBy = ?
                       WHERE deviceId = ? AND companyId = ?",
                    [
                        ($userId !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userId)) ? $userId : null,
                        $deviceId,
                        $companyId,
                    ]
                );
                // Invalidación inmediata del cache file (no esperar TTL 60s)
                jwtInvalidateDeviceCache($deviceId);
            } catch (\Throwable $e) {
                error_log('[logout] revoke device falló: ' . $e->getMessage());
                // Aunque falle el revoke server-side, igual matamos la cookie.
            }
        }
    }
}

// Mata la cookie `_jwt` — expires en el pasado + path=/ (mismo path que jwtSetCookie)
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
setcookie('_jwt', '', [
    'expires'  => 1,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Strict',
    'secure'   => $isHttps,
]);

echo json_encode(['ok' => true]);
