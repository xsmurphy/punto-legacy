<?php
/**
 * Sesiones opacas server-side (auth_session). Reemplaza el modelo JWT stateless.
 *
 * UN solo resolver para todos los realms (panel/pos-app/admin/screen):
 *   authResolve(array $realms): bool  → define AUTHED_* y retorna true; 401 si inválido.
 *
 * Token: 'pt_' . base64url(32 bytes random). En DB se guarda SOLO sha256(token) hex.
 * Revocación: UPDATE auth_session SET status=0. El realm es COLUMNA, no claim firmado.
 *
 * DB autoritativa (SELECT indexado por tokenHash UNIQUE). Cache Redis opcional en F7
 * (gated por AUTH_SESSION_CACHE=1, default OFF). NO usa ncmInsert (SQL explícito).
 */

/** Genera un token opaco crudo (se entrega al cliente UNA vez; no se persiste el crudo). */
function authTokenGenerate(): string
{
    return 'pt_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/** Hash determinístico del token para guardar/buscar (nunca el crudo). */
function authHashToken(string $raw): string
{
    return hash('sha256', $raw);
}

/**
 * Crea una sesión y devuelve el token CRUDO.
 * $f: companyId, userId, deviceId, outletId, registerId, roleId, module, expiresAt
 *     (string 'Y-m-d H:i:s' | null), meta (array), ip (string|null).
 */
function authSessionCreate(string $realm, array $f): string
{
    global $db;
    $raw  = authTokenGenerate();
    $hash = authHashToken($raw);
    $ua   = $f['userAgent'] ?? substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);
    $ip   = $f['ip'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null);
    if (is_string($ip) && strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $ip = ($ip && filter_var($ip, FILTER_VALIDATE_IP) !== false) ? $ip : null;

    $db->Execute(
        'INSERT INTO auth_session
           (tokenhash, realm, companyid, userid, deviceid, outletid, registerid,
            roleid, module, status, meta, expiresat, useragent, ipfirst, iplast, lastseenat)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?::jsonb, ?, ?, ?, ?, now())',
        [
            $hash,
            $realm,
            ($f['companyId'] ?? '')   ?: null,
            ($f['userId'] ?? '')      ?: null,
            ($f['deviceId'] ?? '')    ?: null,
            ($f['outletId'] ?? '')    ?: null,
            ($f['registerId'] ?? '')  ?: null,
            isset($f['roleId']) && $f['roleId'] !== '' ? (string)$f['roleId'] : null,
            ($f['module'] ?? '')      ?: null,
            json_encode($f['meta'] ?? [], JSON_UNESCAPED_UNICODE),
            $f['expiresAt'] ?? null,
            $ua ?: null,
            $ip,
            $ip,
        ]
    );
    return $raw;
}

/**
 * Busca la sesión por token crudo. Devuelve la fila (CaseInsensitiveArray) o null.
 * NO valida status/expiry/realm — eso lo hace authResolve.
 */
function authSessionLookup(string $raw)
{
    global $db;
    if (!isset($db) || !is_object($db) || $raw === '') {
        return null;
    }
    $hash = authHashToken($raw);

    // Cache Redis opcional (F7). Default OFF.
    if (($_ENV['AUTH_SESSION_CACHE'] ?? '') === '1') {
        $cached = _authCacheGet($hash);
        if ($cached !== null) {
            return $cached === false ? null : $cached; // false = tombstone (no existe)
        }
    }

    try {
        $r = $db->Execute(
            'SELECT sessionid, realm, companyid, userid, deviceid, outletid,
                    registerid, roleid, module, status, expiresat
               FROM auth_session WHERE tokenhash = ? LIMIT 1',
            [$hash]
        );
    } catch (\Throwable $e) {
        error_log('[auth_session] lookup falló: ' . $e->getMessage());
        return null;
    }
    if (!$r || $r->EOF) {
        if (($_ENV['AUTH_SESSION_CACHE'] ?? '') === '1') { _authCacheSetTombstone($hash); }
        return null;
    }
    $row = $r->fields; // CaseInsensitiveArray — NO castear a array (trap §40.3)
    if (($_ENV['AUTH_SESSION_CACHE'] ?? '') === '1') { _authCacheSet($hash, $row); }
    return $row;
}

/**
 * Resolver ÚNICO. Recorre los tokens candidatos de la request y elige el primero
 * activo, no expirado, del realm permitido. Mata 401 si ninguno sobrevive.
 *
 * Mantiene la robustez multi-candidato (el browser puede llevar cookie _jwt_panel
 * + Bearer device a la vez): un candidato de otro realm/revocado se DESCARTA, no
 * mata el request (incidente 2026-06-26).
 *
 * ORDEN Bearer → cookies: correcto para devices puros (POS sin panel) y NO
 * cambia. INVARIANTE que sostiene ese orden: un cliente NUNCA debe mandar
 * credenciales de dos realms a la vez — el panel (`lib/api-client.ts`) es
 * SOLO cookie, el POS (`lib/api/pos-client.ts` / `pos-fetch.ts`) es SOLO
 * Bearer. Si ambas llegan juntas de un mismo cliente bien configurado, es
 * el browser del operador (panel cookie) con una caja pareada en el mismo
 * dispositivo (Bearer del device en localStorage) — dos requests DISTINTAS,
 * cada una con su propia credencial, nunca ambas en una request. Ver bug
 * real que motivó esta invariante: el fallback de Bearer automático que
 * `api-client.ts` tenía (removido) hacía que requests de PANEL viajaran con
 * Bearer de device → autenticaban como DEVICE → outlet scope equivocado
 * (espacios creados en la sucursal del device, no la elegida en el panel).
 */
function authResolve(array $allowedRealms = ['pos-app']): bool
{
    $candidates = _authExtractTokens();
    if (empty($candidates)) {
        return false; // sin token → el caller decide (legacy path / 401 propio)
    }

    $session = null;
    $sawWrongRealm = false;
    $sawRevoked    = false;
    $seenRealms    = [];
    foreach ($candidates as $raw) {
        $s = authSessionLookup($raw);
        if ($s === null) {
            continue; // token desconocido (JWT viejo, basura) → probar siguiente
        }
        if ((int)$s['status'] !== 1) {
            $sawRevoked = true;
            continue;
        }
        $exp = (string)($s['expiresAt'] ?? '');
        if ($exp !== '' && strtotime($exp) < time()) {
            continue; // expirada
        }
        // Señal de cliente mal configurado: credenciales VÁLIDAS de dos realms
        // distintos en la MISMA request (ver invariante arriba). No cambia el
        // resultado — solo lo logueamos para poder auditar/cazar regresiones.
        $seenRealms[(string)$s['realm']] = true;
        if (!in_array((string)$s['realm'], $allowedRealms, true)) {
            $sawWrongRealm = true;
            continue;
        }
        if ($session === null) {
            $session = $s;
        }
        // NO break: seguimos inspeccionando los candidatos restantes SOLO para
        // poblar $seenRealms (el diagnóstico de credenciales cruzadas de abajo).
        // Sin esto, el primer match corta el loop y el log jamás ve el segundo
        // realm — exactamente el caso que queremos cazar (blind-spot, P1 del
        // code review). El costo extra es ≤2 lookups cacheables por request
        // y solo cuando hay múltiples credenciales.
    }
    if (count($seenRealms) > 1) {
        error_log(sprintf(
            '[auth_session] request con credenciales válidas de %d realms distintos (%s) — %s %s',
            count($seenRealms),
            implode(',', array_keys($seenRealms)),
            $_SERVER['REQUEST_METHOD'] ?? '?',
            $_SERVER['REQUEST_URI'] ?? '?',
        ));
    }

    if ($session === null) {
        // Diagnóstico del 401 de auth — SIN material sensible (nunca el token,
        // ni su hash). Existe porque "Token de otro realm" se repitió durante
        // meses en producción sin forma de saber qué había llegado realmente:
        // si el header Authorization llegó al PHP (no siempre lo expone el SAPI
        // como HTTP_AUTHORIZATION), cuántas credenciales traía la request y qué
        // realms se reconocieron. Con esto, el próximo caso se diagnostica
        // leyendo una línea en vez de reproduciendo a ciegas.
        error_log(sprintf(
            '[auth_session] 401 %s %s — authHeader=%s candidatos=%d realmsVistos=[%s] revocada=%s realmAjeno=%s esperados=[%s]',
            $_SERVER['REQUEST_METHOD'] ?? '?',
            $_SERVER['REQUEST_URI'] ?? '?',
            empty($_SERVER['HTTP_AUTHORIZATION']) ? 'no' : 'si',
            count($candidates),
            implode(',', array_keys($seenRealms)),
            $sawRevoked ? 'si' : 'no',
            $sawWrongRealm ? 'si' : 'no',
            implode(',', $allowedRealms)
        ));
        http_response_code(401);
        header('Content-Type: application/json');
        // La revocación manda sobre el realm equivocado, SIEMPRE.
        //
        // Antes esto exigía `!$sawWrongRealm`, y esa condición casi nunca se
        // cumple en el browser del operador: por el modelo de doble sesión ahí
        // conviven la cookie del panel y el Bearer del device, así que revocar
        // el device dejaba `$sawWrongRealm=true` por la cookie y el 401 salía
        // como "Token de otro realm" — un mensaje que apunta a un bug de
        // cliente mal configurado cuando en realidad el dispositivo estaba
        // revocado y solo hacía falta reconectarlo. Costó un diagnóstico en
        // producción (2026-07-28, selección de caja en /pos).
        //
        // "Revocada" es la causa concreta y accionable; "otro realm" es el
        // fallback para cuando NO hubo ninguna credencial revocada.
        if ($sawRevoked) {
            die(json_encode(['error' => 'Sesión revocada por el administrador', 'code' => 'session_revoked']));
        }
        die(json_encode([
            'error' => $sawWrongRealm ? 'Token de otro realm' : 'Sesión inválida o expirada',
            'code'  => 401,
        ]));
    }

    define('AUTHED_USER_ID',     (string)($session['userId']     ?? ''));
    define('AUTHED_COMPANY_ID',  (string)($session['companyId']  ?? ''));
    define('AUTHED_OUTLET_ID',   (string)($session['outletId']   ?? ''));
    define('AUTHED_REGISTER_ID', (string)($session['registerId'] ?? ''));
    define('AUTHED_ROLE_ID',     (string)($session['roleId']     ?? ''));
    define('AUTHED_DEVICE_ID',   (string)($session['deviceId']   ?? ''));
    define('AUTHED_REALM',       (string)$session['realm']);
    define('AUTHED_SESSION_ID',  (string)$session['sessionId']);

    authSessionTouch((string)$session['sessionId']);
    return true;
}

/** Actualiza lastSeenAt/ipLast (throttle 60s a nivel DB — no escribe si está fresco). */
function authSessionTouch(string $sessionId): void
{
    global $db;
    if ($sessionId === '') {
        return;
    }
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if (is_string($ip) && strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    $ip = ($ip && filter_var($ip, FILTER_VALIDATE_IP) !== false) ? $ip : null;
    try {
        $db->Execute(
            'UPDATE auth_session SET lastseenat = now(), iplast = ?
               WHERE sessionid = ?
                 AND (lastseenat IS NULL OR lastseenat < now() - interval \'60 seconds\')',
            [$ip, $sessionId]
        );
    } catch (\Throwable $e) {
        // best-effort — nunca bloquear por el touch
    }
}

/** Revoca por sessionId (logout de panel/admin, UI de sesiones). */
function authSessionRevokeBySessionId(string $sessionId, ?string $revokedBy = null): void
{
    global $db;
    if ($sessionId === '') { return; }
    try {
        $r = $db->Execute(
            'UPDATE auth_session SET status = 0, revokedat = now(), revokedby = ?
               WHERE sessionid = ? RETURNING tokenhash',
            [$revokedBy ?: null, $sessionId]
        );
        if ($r && !$r->EOF) { _authCacheDel((string)($r->fields['tokenHash'] ?? '')); }
    } catch (\Throwable $e) {
        error_log('[auth_session] revoke bySessionId falló: ' . $e->getMessage());
    }
}

/** Revoca por token crudo (logout que solo tiene el token, ej. POS). */
function authSessionRevokeByToken(string $raw): void
{
    global $db;
    if ($raw === '') { return; }
    $hash = authHashToken($raw);
    try {
        $db->Execute(
            'UPDATE auth_session SET status = 0, revokedat = now() WHERE tokenhash = ?',
            [$hash]
        );
        _authCacheDel($hash);
    } catch (\Throwable $e) {
        error_log('[auth_session] revoke byToken falló: ' . $e->getMessage());
    }
}

/** Revoca TODAS las sesiones de un device (cuando el panel revoca un dispositivo). */
function authSessionRevokeByDevice(string $deviceId, string $companyId, ?string $revokedBy = null): void
{
    global $db;
    if ($deviceId === '' || $companyId === '') { return; }
    try {
        $r = $db->Execute(
            'UPDATE auth_session SET status = 0, revokedat = now(), revokedby = ?
               WHERE deviceid = ? AND companyid = ? AND status = 1
             RETURNING tokenhash',
            [$revokedBy ?: null, $deviceId, $companyId]
        );
        while ($r && !$r->EOF) {
            _authCacheDel((string)($r->fields['tokenHash'] ?? ''));
            $r->MoveNext();
        }
    } catch (\Throwable $e) {
        error_log('[auth_session] revoke byDevice falló: ' . $e->getMessage());
    }
}

/**
 * Devuelve los tokens candidatos de la request (Bearer + cookies + POST), sin filtrar
 * por realm. Tokens opacos (prefix pt_); los JWT viejos simplemente no resuelven.
 */
function _authExtractTokens(): array
{
    $tokens = [];
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        $tokens[] = $m[1];
    }
    foreach (['_jwt_panel', '_jwt_admin', '_jwt'] as $c) {
        if (!empty($_COOKIE[$c])) { $tokens[] = $_COOKIE[$c]; }
    }
    foreach (['_jwt_panel', '_jwt'] as $c) {
        if (!empty($_POST[$c])) { $tokens[] = $_POST[$c]; }
    }
    return array_values(array_unique($tokens));
}

/**
 * Setea un cookie opaco (cualquier nombre). Generaliza el viejo jwtSetCookie.
 * $sameSite: 'Lax' (panel/pos) | 'Strict' (admin/impersonación).
 */
function authSetOpaqueCookie(string $name, string $raw, int $ttl, string $sameSite = 'Lax'): void
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $maxAge = ($ttl > 0) ? $ttl : (400 * 86400); // 0 = eterno → 400d (máx Chrome)
    $opts = [
        'expires'  => time() + $maxAge,
        'path'     => '/',
        'httponly' => true,
        'samesite' => $sameSite,
        'secure'   => $isHttps,
    ];
    $dom = $_ENV['COOKIE_DOMAIN'] ?? '';
    if ($dom !== '') { $opts['domain'] = $dom; }
    setcookie($name, $raw, $opts);
}

/** Limpia un cookie (logout). */
function authClearCookie(string $name, string $sameSite = 'Lax'): void
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $opts = ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => $sameSite, 'secure' => $isHttps];
    $dom = $_ENV['COOKIE_DOMAIN'] ?? '';
    if ($dom !== '') { $opts['domain'] = $dom; }
    setcookie($name, '', $opts);
}

// ---------------------------------------------------------------------------
// Cache Redis (F7) — stubs no-op hasta que se active. Implementación real abajo
// en la sección F7. Mientras AUTH_SESSION_CACHE != '1', estas no se llaman.
// ---------------------------------------------------------------------------
if (!function_exists('_authCacheGet')) {
    function _authCacheGet(string $hash) { return null; }            // null = miss
    function _authCacheSet(string $hash, $row): void {}
    function _authCacheSetTombstone(string $hash): void {}
    function _authCacheDel(string $hash): void {}
}
