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
 * Resolver ÚNICO. Elige la credencial de la request y define las constantes
 * AUTHED_*. Mata 401 si ninguna sobrevive.
 *
 * ── PRECEDENCIA (invariante, 2026-08-25) ────────────────────────────────────
 * Si la request trae `Authorization: Bearer`, el Bearer DEFINE el realm y las
 * credenciales ambientales (cookies `_jwt_panel`/`_jwt_admin`/`_jwt` y los
 * tokens en $_POST) se IGNORAN POR COMPLETO. La cookie solo cuenta cuando NO
 * hay Bearer. No hay "primera credencial válida gana": hay una credencial
 * explícita que manda, y un fallback ambiental que solo aplica en su ausencia.
 *
 * Consecuencia deliberada: un Bearer revocado/expirado/de otro realm devuelve
 * 401 aunque la cookie del operador sea válida. Ese 401 es la respuesta
 * CORRECTA — es lo que dispara el self-healing del device en `pos-fetch.ts`.
 * Antes, la cookie lo "rescataba" y el POS seguía operando como panel.
 *
 * ── Por qué: tres incidentes de la misma clase en dos meses ─────────────────
 * La raíz común es un browser que lleva las DOS credenciales a la vez (modelo
 * de doble sesión: el operador usa panel y caja en la misma máquina, cookie
 * `_jwt_panel` + Bearer del device en localStorage) contra endpoints
 * multi-realm que aceptaban cualquiera de las dos.
 *
 *   1. 2026-07-19 — el Bearer automático de `api-client.ts` (removido) hacía
 *      que requests del PANEL viajaran con Bearer del device y autenticaran
 *      como device: espacios creados en la sucursal del device, no en la
 *      elegida en el panel.
 *   2. 2026-08-24 — `/v1/users` con Bearer de device → 403 silencioso → lock
 *      screen sin PINs.
 *   3. 2026-08-25 — `/api/pos/bootstrap` SIN Bearer resolvía como panel por la
 *      cookie y respondía 200 sin el roster (que solo se sirve a `pos-app`).
 *      Ese 200 envenenó el cache y dejó un iPhone recién pareado bloqueado.
 *
 * Cada fix anterior fue local (un call-site, un endpoint). Esta precedencia es
 * la regla compartida que elimina la ambigüedad para TODOS los endpoints.
 *
 * El realm sigue siendo columna de `auth_session` y el modelo multi-realm no
 * cambia (context/21): lo que muere es la ambigüedad de resolución.
 *
 * Complemento estructural en el borde: `/api/pos/*` (frontend/lib/bff/proxy.ts)
 * NO reenvía el header `cookie` upstream, así que por esa puerta llega UNA sola
 * credencial. Esta precedencia cubre la otra puerta — el catch-all
 * `/api/v1/[...path]`, que el POS usa con Bearer para ventas y cotizaciones y
 * el panel con cookie.
 *
 * Se mantiene la robustez multi-candidato DENTRO de cada grupo (incidente
 * 2026-06-26): con varias cookies presentes y sin Bearer, una de realm ajeno o
 * revocada se descarta y se sigue probando, no mata el request.
 */
function authResolve(array $allowedRealms = ['pos-app']): bool
{
    $bearer  = _authBearerToken();
    $ambient = _authAmbientTokens();

    // El Bearer manda: si está presente, es la ÚNICA credencial considerada.
    $candidates      = $bearer !== '' ? [$bearer] : $ambient;
    $ignoredAmbient  = ($bearer !== '' ? count($ambient) : 0);

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
        $seenRealms[(string)$s['realm']] = true;
        if (!in_array((string)$s['realm'], $allowedRealms, true)) {
            $sawWrongRealm = true;
            continue;
        }
        if ($session === null) {
            $session = $s;
        }
        // NO break: seguimos inspeccionando los candidatos restantes SOLO para
        // poblar $seenRealms (el diagnóstico de abajo). Con Bearer presente hay
        // un único candidato y el loop corre una vez; sin Bearer el costo extra
        // es ≤2 lookups cacheables, y solo cuando hay múltiples cookies.
    }
    if (count($seenRealms) > 1) {
        // Sin Bearer y con cookies válidas de dos realms distintos. Ya no puede
        // pasar con Bearer (la precedencia lo hace imposible), así que si esto
        // aparece es un cliente mandando dos cookies de realms distintos.
        error_log(sprintf(
            '[auth_session] request con cookies válidas de %d realms distintos (%s) — %s %s',
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
        // `cookiesIgnoradas` es la señal nueva (precedencia 2026-08-25): dice
        // cuántas credenciales ambientales había cuando el Bearer no resolvió.
        // Con >0 acá, el modelo VIEJO habría respondido 200 con el realm de la
        // cookie en vez de este 401 — es exactamente la clase de bug que la
        // precedencia elimina, y la línea que lo prueba al revisar logs.
        error_log(sprintf(
            '[auth_session] 401 %s %s — authHeader=%s candidatos=%d cookiesIgnoradas=%d realmsVistos=[%s] revocada=%s realmAjeno=%s esperados=[%s]',
            $_SERVER['REQUEST_METHOD'] ?? '?',
            $_SERVER['REQUEST_URI'] ?? '?',
            empty($_SERVER['HTTP_AUTHORIZATION']) ? 'no' : 'si',
            count($candidates),
            $ignoredAmbient,
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
 * El token del header `Authorization: Bearer` ('' si no vino).
 *
 * Credencial EXPLÍCITA: el cliente la adjunta a propósito en esta request. Por
 * eso manda sobre las ambientales — ver la precedencia en authResolve().
 */
function _authBearerToken(): string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Credenciales AMBIENTALES: cookies y tokens en $_POST. El browser las adjunta
 * solo por estar en el mismo origen — el código que hace la request no eligió
 * mandarlas. Solo cuentan cuando NO hay Bearer (ver authResolve()).
 *
 * Tokens opacos (prefix pt_); los JWT viejos simplemente no resuelven.
 */
function _authAmbientTokens(): array
{
    $tokens = [];
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
