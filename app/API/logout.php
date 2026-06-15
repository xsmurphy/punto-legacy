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

// El browser puede mandar `_jwt` (POS) y `_jwt_panel` (panel) a la vez en
// app.punto.la. Logout revoca el device del POS, así que elegimos el candidato
// `iss=pos-app` entre todos los presentes (no el primero a ciegas — sino un
// `_jwt_panel` presente haría que logout no revoque nada).
$payload = null;
if ($secret) {
    foreach (_jwtExtractTokens() as $candidate) {
        $decoded = jwtDecode($candidate, $secret);
        if (is_array($decoded) && ($decoded['iss'] ?? '') === 'pos-app') {
            $payload = $decoded;
            break;
        }
    }
}

if ($payload !== null) {
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

// Mata la cookie `_jwt` — mismos atributos que jwtSetCookie (path/httponly/
// samesite/secure) para que el browser la matchee y la borre. La detección
// HTTPS lee X-Forwarded-Proto: detrás de Traefik/Cloudflare $_SERVER['HTTPS']
// es 'off' aunque el cliente sea HTTPS, y un Secure distinto al del set puede
// impedir el borrado.
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
setcookie('_jwt', '', [
    'expires'  => 1,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $isHttps,
]);

echo json_encode(['ok' => true]);
