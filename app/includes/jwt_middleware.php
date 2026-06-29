<?php
/**
 * JWT Middleware para el módulo /app.
 *
 * Lee TODOS los tokens candidatos de la request (ver _jwtExtractTokens):
 *   1. Header  Authorization: Bearer <token>  ← device POS (localStorage)
 *   2. Cookie  _jwt_panel (realm panel)
 *   3. POST    _jwt_panel y _jwt (back-compat programáticos)
 * y elige el que matchea el realm del endpoint (allowlist contra el claim `iss`).
 * El browser puede mandar `_jwt` y `_jwt_panel` a la vez en app.punto.la — por
 * eso se selecciona por realm, no "el primero".
 *
 * Comportamiento:
 *   - Token válido del realm → define AUTHED_* constants, retorna true
 *   - Sin ningún token       → retorna false (sigue la ruta legacy)
 *   - Token inválido / de otro realm → responde 401 y muere
 *
 * Dependencias: jwt.php, simple.config.php (para leer JWT_SECRET desde $_ENV)
 */

function jwtAuthenticate(array $allowedRealms = ['pos-app']): bool
{
    require_once __DIR__ . '/auth_session.php';
    return authResolve($allowedRealms);
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

    // TTL=0 → JWT eterno (sin claim `exp`). Para que el cookie tampoco se borre
    // antes de tiempo, usamos 400 días que es el máximo que Chrome respeta desde
    // 2022 (RFC 6265bis). Al expirar el cookie, el user se re-loguea, pero el
    // token podría ser refrescado server-side antes de eso si se implementa.
    $cookieMaxAge = ($ttl > 0) ? $ttl : (400 * 86400);

    // SameSite=Lax (no Strict): Strict bloquea la cookie en navegaciones top-level
    // cross-origin (ej. user llega de un email/redirect post-login) y ha causado
    // bugs raros con XHR en algunos browsers. Lax es seguro para flujos normales
    // y es el default de Chrome moderno.
    setcookie('_jwt', $token, [
        'expires'  => time() + $cookieMaxAge,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
}

/**
 * Devuelve TODOS los tokens candidatos presentes en la request, en orden de
 * prioridad, SIN filtrar por realm. La selección por realm la hace el caller
 * (jwtAuthenticate / refresh / logout) contra el claim `iss`.
 *
 * Por qué una lista y no "el primero": el browser puede mandar `_jwt_panel`
 * (panel, domain `.punto.la`) a la vez que un Bearer token del device en
 * `app.punto.la`. El caller recorre los candidatos y se queda con el que
 * matchea su realm.
 *
 * Fuentes en orden de prioridad:
 *   1. Header `Authorization: Bearer <token>` — device POS (localStorage)
 *   2. Cookie `_jwt_panel`                   — panel admin (HttpOnly, 24h)
 *   3. POST `_jwt_panel`                     — clientes programáticos panel
 *   4. POST `_jwt`                           — clientes programáticos legacy
 *
 * @return string[] tokens crudos (sin decodificar), puede estar vacío.
 */
function _jwtExtractTokens(): array
{
    $tokens = [];

    // 1. Authorization header (Bearer) — device POS via localStorage.
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
        $tokens[] = $m[1];
    }

    // 2. Cookie `_jwt_panel` — panel admin (HttpOnly, 24h).
    // La cookie `_jwt` del device fue eliminada: el device token viaja como Bearer.
    if (!empty($_COOKIE['_jwt_panel'])) {
        $tokens[] = $_COOKIE['_jwt_panel'];
    }

    // 3. POST field (clientes programáticos).
    if (!empty($_POST['_jwt_panel'])) {
        $tokens[] = $_POST['_jwt_panel'];
    }
    // Back-compat para clients programáticos que aún mandan _jwt por POST.
    if (!empty($_POST['_jwt'])) {
        $tokens[] = $_POST['_jwt'];
    }

    return $tokens;
}

/**
 * Back-compat: primer candidato según prioridad (header > cookie panel >
 * cookie POS > POST). Realm-agnóstico — callers que necesiten un realm
 * específico deben recorrer _jwtExtractTokens() y filtrar por `iss`.
 */
function _jwtExtractToken(): ?string
{
    return _jwtExtractTokens()[0] ?? null;
}
