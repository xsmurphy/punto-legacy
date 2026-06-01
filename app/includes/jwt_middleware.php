<?php
/**
 * JWT Middleware para el módulo /app.
 *
 * Lee el token desde (en orden de prioridad):
 *   1. Header  Authorization: Bearer <token>
 *   2. Cookie  _jwt
 *   3. POST    _jwt
 *
 * Comportamiento:
 *   - Token válido   → define AUTHED_* constants, retorna true
 *   - Sin token      → retorna false (sigue la ruta legacy)
 *   - Token inválido → responde 401 y muere (ataque activo o token expirado)
 *
 * Dependencias: jwt.php, simple.config.php (para leer JWT_SECRET desde $_ENV)
 */

function jwtAuthenticate(): bool
{
    require_once __DIR__ . '/jwt.php';

    $secret = $_ENV['JWT_SECRET'] ?? '';
    if (!$secret) {
        // JWT no configurado: no bloquear, dejar pasar al legacy
        return false;
    }

    $token = _jwtExtractToken();

    if ($token === null) {
        // Sin token: legacy path
        return false;
    }

    $payload = jwtDecode($token, $secret);

    if ($payload === null) {
        // Token presente pero inválido o expirado
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode([
            'error' => 'Token inválido o expirado',
            'code'  => 401,
        ]));
    }

    // Realm gate: JWT_SECRET es COMPARTIDO entre POS y panel; el claim `iss`
    // separa los realms para que un token panel NO autentique en /app/action.php
    // /load.php/etc. (privilege confusion). Tokens sin `iss` (emitidos antes del
    // fix) → rechazados; pre-producción, forzar re-login es aceptable.
    if (($payload['iss'] ?? '') !== 'pos-app') {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode([
            'error' => 'Token de otro realm',
            'code'  => 401,
        ]));
    }

    // Definir identidad autenticada como constantes PHP.
    // IDs son UUIDs (string) desde Phase UUID; role sigue siendo int.
    define('AUTHED_USER_ID',     (string)($payload['sub']  ?? ''));
    define('AUTHED_COMPANY_ID',  (string)($payload['cid']  ?? ''));
    define('AUTHED_OUTLET_ID',   (string)($payload['oid']  ?? ''));
    define('AUTHED_REGISTER_ID', (string)($payload['rid']  ?? ''));
    define('AUTHED_ROLE_ID',     (int)($payload['role'] ?? 0));

    return true;
}

/**
 * Emite un cookie JWT seguro.
 * Centraliza la configuración del cookie para login.php y refresh.php.
 */
function jwtSetCookie(string $token, int $ttl): void
{
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie('_jwt', $token, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => $isHttps,
    ]);
}

function _jwtExtractToken(): ?string
{
    // 1. Authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        return $m[1];
    }

    // 2. Cookie (browser envía automáticamente — cero cambios JS necesarios)
    if (!empty($_COOKIE['_jwt'])) {
        return $_COOKIE['_jwt'];
    }

    // 3. POST field (clientes programáticos)
    if (!empty($_POST['_jwt'])) {
        return $_POST['_jwt'];
    }

    return null;
}
