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

    // Device pairing — el JWT de /app representa un emparejamiento (TTL 10 años,
    // §28). Si el panel tenant revoca el device, este check lo corta con cache
    // 60s (file). Tokens viejos (pre-device) NO tienen `did` y siguen pasando
    // por compatibilidad — al re-loguear se les asigna device row + did nuevo.
    $deviceId = (string)($payload['did'] ?? '');
    if ($deviceId !== '' && jwtIsDeviceRevoked($deviceId, (string)($payload['cid'] ?? ''))) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode([
            'error' => 'Dispositivo desactivado por el administrador',
            'code'  => 'device_revoked',
        ]));
    }

    // Definir identidad autenticada como constantes PHP.
    // IDs son UUIDs (string) desde Phase UUID; role sigue siendo int.
    define('AUTHED_USER_ID',     (string)($payload['sub']  ?? ''));
    define('AUTHED_COMPANY_ID',  (string)($payload['cid']  ?? ''));
    define('AUTHED_OUTLET_ID',   (string)($payload['oid']  ?? ''));
    define('AUTHED_REGISTER_ID', (string)($payload['rid']  ?? ''));
    define('AUTHED_ROLE_ID',     (int)($payload['role'] ?? 0));
    define('AUTHED_DEVICE_ID',   $deviceId);

    return true;
}

/**
 * Chequea si un device está revocado. Cache file 60s.
 *
 * No hace SELECT por cada request: usa un archivo de cache con mtime para
 * el TTL. El admin tarda ~60s en ver efecto al revocar, lo cual es aceptable
 * para el caso de uso (empleado renunciado / despedido). Para revocación
 * inmediata habría que bajar TTL o reemplazar por Redis pub/sub.
 *
 * Si la query a la BD falla (DB caída, $db global no cargado todavía), por
 * conservadurismo NO bloqueamos — el cache previo se respeta y si no hay,
 * dejamos pasar (sería peor bloquear todo el POS por un fallo de DB).
 */
function jwtIsDeviceRevoked(string $deviceId, string $companyId): bool
{
    if ($deviceId === '' || $companyId === '') {
        return false;
    }
    // Validar formato UUID para no usar input crudo en path
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
        return false;
    }

    // Cache key incluye companyId — defense-in-depth: aunque el JWT firmado
    // ata el deviceId a su cid, NO queremos que el cache pueda mezclar
    // companies si por algún motivo el mismo deviceId aparece bajo dos cids
    // (debería ser imposible con UUIDs, pero el costo de incluirlo es cero).
    $cacheDir  = sys_get_temp_dir() . '/punto_device_status';
    // Sanitizar companyId con la misma regex para evitar path injection
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $companyId)) {
        return false;
    }
    $cacheFile = $cacheDir . '/' . $deviceId . '_' . $companyId . '.dat';
    $ttl       = 60;

    // Cache HIT — leemos el status cacheado si está fresco
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $cached = trim((string)@file_get_contents($cacheFile));
        return $cached === '0';  // 0 = revoked
    }

    // Cache MISS / STALE — query a la BD
    global $db;
    if (!isset($db) || !is_object($db)) {
        // BD no disponible (middleware corrió antes de head.php). Modo conservador:
        // si hay cache previo (puede estar stale), lo usamos; sino dejamos pasar.
        if (is_file($cacheFile)) {
            $cached = trim((string)@file_get_contents($cacheFile));
            return $cached === '0';
        }
        return false;
    }

    // companyId del cache key viene del JWT firmado — el SELECT lo bindea
    // doble (queda como segundo guard si por bug futuro el filename cambiase)

    try {
        $r = $db->Execute(
            'SELECT status FROM device WHERE deviceId = ? AND companyId = ? LIMIT 1',
            [$deviceId, $companyId]
        );
    } catch (\Throwable $e) {
        // Falla de query — no bloquear el POS por esto
        error_log('[jwt_middleware] device status query failed: ' . $e->getMessage());
        return false;
    }

    $status = 1; // default activo si no existe la row (compat con tokens viejos)
    $exists = false;
    if ($r && !$r->EOF) {
        $status = (int)($r->fields['status'] ?? 1);
        $exists = true;
    }

    // Escribir cache (sólo si la row existe — un device fantasma no merece cache)
    if ($exists) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0700, true);
        }
        @file_put_contents($cacheFile, (string)$status, LOCK_EX);
    }

    return $status === 0;
}

/**
 * Invalidación manual del cache de un device. Llamar después de revoke/rename
 * desde el panel para que el efecto sea inmediato (en vez de esperar TTL 60s).
 * Borra todos los archivos `{deviceId}_*.dat` (cualquier companyId del key).
 */
function jwtInvalidateDeviceCache(string $deviceId): void
{
    if ($deviceId === '' ||
        !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
        return;
    }
    $glob = sys_get_temp_dir() . '/punto_device_status/' . $deviceId . '_*.dat';
    foreach ((array)@glob($glob) as $f) {
        @unlink($f);
    }
}

/**
 * Emite un cookie JWT seguro.
 * Centraliza la configuración del cookie para login.php y refresh.php.
 */
function jwtSetCookie(string $token, int $ttl): void
{
    // Detección HTTPS correcta detrás de reverse proxy (Traefik en Coolify, nginx,
    // Cloudflare, etc.). $_SERVER['HTTPS'] es false porque PHP-S no termina TLS;
    // X-Forwarded-Proto es el header estándar que Traefik agrega para indicar el
    // protocolo original del cliente.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    // SameSite=Lax (no Strict): Strict bloquea la cookie en navegaciones top-level
    // cross-origin (ej. user llega de un email/redirect post-login) y ha causado
    // bugs raros con XHR en algunos browsers. Lax es seguro para flujos normales
    // y es el default de Chrome moderno.
    setcookie('_jwt', $token, [
        'expires'  => time() + $ttl,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
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
