<!-- REGLA: doc de plan cerrado. Las decisiones están tomadas (no relitigar). Sonnet
     ejecuta transcribiendo el código de abajo. Actualizar el changelog al final por fase. -->

# 21 — Auth rewrite: JWT stateless → tokens opacos revocables (server-side sessions)

> **Estado:** plan cerrado 2026-06-29. Diseño y lógica por Opus; ejecución por Sonnet
> (transcripción — no rediseñar, no inventar lógica). Cutover limpio (todos re-loguean una vez).

## Decisiones tomadas (NO relitigar)

1. **Un solo mecanismo de auth** para panel, POS, Checkout Screen y los N módulos que
   consumen `/api`: token **opaco** (random server-side), validado por **un único
   resolver** `authResolve($realms)`. El realm pasa de ser claim criptográfico (`iss`)
   a ser **columna** de la sesión.
2. **Stateful y revocable**: tabla `auth_session`. Revocación = `UPDATE status=0`.
   Reemplaza el modelo stateless del JWT y el hack de file-cache de `jwtIsDeviceRevoked`.
3. **DB autoritativa** en el hot-path (un SELECT indexado por `tokenHash` único).
   Redis es **cache opcional** (F7, gated por env, default OFF) — NO se mete RESP
   hand-rolled en el path de seguridad el día del cutover.
4. **Transporte** (adaptador de 3 líneas, NO segundo mecanismo): **panel/admin = cookie
   HttpOnly** (anti-XSS; admin es cross-tenant); **POS/Screen = Bearer + localStorage**
   (self-heal en 401). Los **nombres de cookie no cambian** (`_jwt_panel`, `_jwt_admin`,
   `_jwt`) — solo cambia el VALOR (JWT → token opaco). Esto evita tocar el catch-all BFF
   y todos los readers de cookie del frontend.
5. **Cutover limpio**: al deploy, los JWT viejos dejan de resolver (no están en
   `auth_session`) → 401 → re-login. Precedente: deploy 2026-06-27 (devices).

## Por qué (diagnóstico del audit)

El sistema YA era stateful a medias: `device` table + `jwtIsDeviceRevoked` (file-cache 60s
en `/tmp`, se pierde en cada deploy) son una capa de revocación colgada del JWT — pero solo
para POS/Screen. Panel y admin seguían siendo JWT puro irrevocable. Resultado: se pagaba la
complejidad del JWT (firma, `iss` como única barrera entre realms con `JWT_SECRET` compartido,
doble secret, doble TTL) **+** la del lookup stateful. Tres validadores divergentes
(`jwtAuthenticate`, `apiMiddleware`, `adminMiddleware`) = bugs que se arreglan en 2-3 lugares.

---

## Modelo de datos

Tabla nueva `auth_session`. **camelCase con quotes** (§44 — tabla nueva). Se consulta con SQL
explícito; **NO** se registra en `_getTableSchema()` ni se usa `ncmInsert` (evita el trap §34).

### Migración `database/migrations/postgres/69_auth_session.sql`

```sql
BEGIN;

CREATE TABLE IF NOT EXISTS auth_session (
  "sessionId"  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  "tokenHash"  char(64) NOT NULL,                 -- sha256 hex del token crudo (nunca se guarda el crudo)
  realm        varchar(16) NOT NULL,              -- 'panel' | 'pos-app' | 'admin' | 'screen'
  "companyId"  uuid,                              -- null para admin (cross-tenant)
  "userId"     uuid,                              -- contactId (tenant) o adminId (admin)
  "deviceId"   uuid,                              -- refiere device.deviceId; null en panel/admin
  "outletId"   uuid,
  "registerId" uuid,
  "roleId"     varchar(64),                       -- role int-as-string legacy o UUID
  module       varchar(32),                       -- 'pos' | 'screen' | 'panel' | 'admin'
  status       smallint NOT NULL DEFAULT 1,       -- 1=activa, 0=revocada
  meta         jsonb NOT NULL DEFAULT '{}'::jsonb,
  "createdAt"  timestamptz NOT NULL DEFAULT now(),
  "lastSeenAt" timestamptz,
  "expiresAt"  timestamptz,                        -- null = nunca expira (device POS eterno)
  "revokedAt"  timestamptz,
  "revokedBy"  uuid,
  "userAgent"  text,
  "ipFirst"    varchar(64),
  "ipLast"     varchar(64)
);

-- Lookup hot-path: igualdad por hash. UNIQUE para idempotencia + plan index-only.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_auth_session_token ON auth_session ("tokenHash");
-- Listado de sesiones por empresa (UI de revocación).
CREATE INDEX IF NOT EXISTS idx_auth_session_company ON auth_session ("companyId", realm, status);
-- Revocación por device (cuando se revoca un device se revocan sus sesiones).
CREATE INDEX IF NOT EXISTS idx_auth_session_device ON auth_session ("deviceId") WHERE "deviceId" IS NOT NULL;

COMMIT;
```

> El número 69 es el próximo libre (último aplicado: `68_device_invitation_reconnect.sql`).
> Runner: `database/migrate.php` (corre en `docker-entrypoint.sh` al boot, idempotente).

---

## Archivo nuevo: `app/includes/auth_session.php`

**Ubicación canónica = `app/includes/auth_session.php`, FUENTE ÚNICA.** A diferencia de
`jwt.php`/`ws_publish.php` (que están DUPLICADOS en `app/includes/` y `panel/includes/` — el
smell de divergencia que este rewrite mata), `auth_session.php` NO se copia: existe en un solo
lugar y todos los realms lo `require_once` por ruta relativa cruzando a `app/includes/`. `/api`
ya reusa `app/includes/` vía `API_APP_DIR`. **Rutas del require por ubicación del caller** (todas
confirmadas con `ls`):

| Caller (dir) | `require_once` |
|--------------|----------------|
| `app/includes/*`, `app/*.php` (handoff) | `__DIR__ . '/auth_session.php'` o `'/includes/auth_session.php'` |
| `app/API/*` | `__DIR__ . '/../includes/auth_session.php'` |
| `api/v1/*` (bootstrap cargado) | `API_APP_DIR . '/includes/auth_session.php'` |
| `panel/*.php` (logout) | `__DIR__ . '/../app/includes/auth_session.php'` |
| `panel/includes/*` | `__DIR__ . '/../../app/includes/auth_session.php'` |
| `panel/bff/*` | `__DIR__ . '/../../app/includes/auth_session.php'` |
| `panel/API/lib/*` | `__DIR__ . '/../../../app/includes/auth_session.php'` |
| `panel/API/v1/admin/*` | `__DIR__ . '/../../../../app/includes/auth_session.php'` |

Es el ÚNICO archivo con la lógica de sesiones. **Escribir tal cual:**

```php
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
           ("tokenHash", realm, "companyId", "userId", "deviceId", "outletId", "registerId",
            "roleId", module, status, meta, "expiresAt", "userAgent", "ipFirst", "ipLast", "lastSeenAt")
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
            'SELECT "sessionId", realm, "companyId", "userId", "deviceId", "outletId",
                    "registerId", "roleId", module, status, "expiresAt"
               FROM auth_session WHERE "tokenHash" = ? LIMIT 1',
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
 * activo, no expirado, del realm permitido. Mata 401 si ninguno sobrevive (no hay
 * fallback legacy en el sistema — pre-producción, decisión owner 2026-06-29).
 *
 * Robustez multi-candidato (el browser puede llevar cookie + Bearer a la vez): un
 * candidato de otro realm/revocado se DESCARTA, no mata el request hasta agotar todos
 * (incidente 2026-06-26). Retorna false SOLO si no hay ningún token presente.
 */
function authResolve(array $allowedRealms = ['pos-app']): bool
{
    $candidates = _authExtractTokens();
    if (empty($candidates)) {
        return false; // sin token → el caller corta 401 (apiAuthTenant / apiMiddleware)
    }

    $session = null;
    $sawWrongRealm = false;
    $sawRevoked    = false;
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
        if (!in_array((string)$s['realm'], $allowedRealms, true)) {
            $sawWrongRealm = true;
            continue;
        }
        $session = $s;
        break;
    }

    if ($session === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        if ($sawRevoked && !$sawWrongRealm) {
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
            'UPDATE auth_session SET "lastSeenAt" = now(), "ipLast" = ?
               WHERE "sessionId" = ?
                 AND ("lastSeenAt" IS NULL OR "lastSeenAt" < now() - interval \'60 seconds\')',
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
            'UPDATE auth_session SET status = 0, "revokedAt" = now(), "revokedBy" = ?
               WHERE "sessionId" = ? RETURNING "tokenHash"',
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
            'UPDATE auth_session SET status = 0, "revokedAt" = now() WHERE "tokenHash" = ?',
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
            'UPDATE auth_session SET status = 0, "revokedAt" = now(), "revokedBy" = ?
               WHERE "deviceId" = ? AND "companyId" = ? AND status = 1
             RETURNING "tokenHash"',
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
```

---

## FASES

Cada fase = un commit. Branch `auth-rewrite` (auto-deploy OFF; el owner deploya al cerrar).
`code-reviewer` OBLIGATORIO en cada fase (auth = alto riesgo).

> ⚠️ **REVISIÓN 2026-06-29 — F2/F3/F4 RE-TARGETEADAS.** El primer relevamiento se perdió toda
> la superficie moderna `api/lib/Auth/`. Los emisores REALES que frontend usa NO son los
> legacy (`issueJwtPanel`, `app/API/auth.php`, `app/handoff.php`, `pos-redirect.php`, `app/login.php`)
> — esos son **código muerto de referencia, NO se tocan**. Los targets correctos están en la
> sección **"## REVISIÓN MODERNA (F2/F3/F4 reales)"** al final de este doc, con código exacto.
> El texto de F2/F3/F4 de abajo quedó OBSOLETO salvo F0/F1 (que sí dieron en el blanco:
> `jwtAuthenticate→authResolve` y `adminMiddleware`). Ejecutar SIEMPRE desde la sección REVISIÓN.

### F0 — Schema + core (sin cablear)

1. Crear `database/migrations/postgres/69_auth_session.sql` (arriba).
2. Crear `app/includes/auth_session.php` (arriba, completo).
3. Verificar que el container arranca y la migración aplica (`docker-entrypoint.sh` →
   `migrate.php`). Nada usa todavía el resolver — sin efecto en runtime.

**Criterio de done:** migración aplicada, `auth_session` existe, archivo carga sin fatales.

---

### F1 — Colapsar los 3 validadores en `authResolve`

**1.1 — `app/includes/jwt_middleware.php`**: reescribir `jwtAuthenticate()` como wrapper.
Reemplazar TODO el cuerpo de la función (líneas 21-115) por:

```php
function jwtAuthenticate(array $allowedRealms = ['pos-app']): bool
{
    require_once __DIR__ . '/auth_session.php';
    return authResolve($allowedRealms);
}
```

Dejar `jwtIsDeviceRevoked`, `jwtSetCookie`, `_jwtExtractTokens` en el archivo por ahora
(se borran en F6). `api/bootstrap.php::apiAuthTenant()` NO se toca — sigue llamando
`jwtAuthenticate($realms)` y leyendo `AUTHED_*` (idénticos). El branch pos-app de
`apiAuthTenant` que lee la tabla `device` para outlet/register **sigue funcionando**
(usa `AUTHED_DEVICE_ID`, que ahora viene de la sesión).

**1.2 — `panel/API/lib/api_middleware.php`**: en `apiMiddleware()`, **eliminar por completo**
el bloque de auth JWT + el `else` legacy (api_key + `_apiTrySessionAuth`), desde
`$jwtSecret = ...` hasta el cierre del `else { ... }`. El sistema **NO tiene cuentas legacy**
(decisión owner 2026-06-29, pre-producción) → no hay fallback que preservar. Reemplazar TODO
ese bloque por:

```php
    // 4. Autenticación: sesión opaca, realm panel. Sin fallback legacy (no hay cuentas legacy).
    // auth_session.php vive SOLO en app/includes/ (fuente única). Desde panel/API/lib/ → cruzar a app:
    require_once __DIR__ . '/../../../app/includes/auth_session.php';

    if (!authResolve(['panel'])) {
        // authResolve corta 401 por sí mismo si hay token inválido/otro realm;
        // retorna false solo si NO hay ningún token → 401 explícito acá.
        apiUnauthorized('Autenticación requerida');
    }

    $eCompanyId = AUTHED_COMPANY_ID;
    define('PANEL_JWT_AUTHED', true);
    define('PANEL_AUTHED_USER', AUTHED_USER_ID);
    define('PANEL_AUTHED_ROLE', (int)AUTHED_ROLE_ID);
    _apiDefineSharedConstants($eCompanyId, AUTHED_COMPANY_ID, AUTHED_OUTLET_ID, AUTHED_REGISTER_ID);
```

Borrar también el `require_once .../jwt.php` del top. Las funciones `_apiExtractJwtToken()`,
`_apiTrySessionAuth()`, `validateAPIAccess()` quedan **sin callers** → marcarlas `@deprecated`
y borrarlas en F6 (o borrarlas ya si no las usa nadie más — `grep -rn` antes).

> **`apiMiddlewarePublic()` NO se toca** — es el path sin-auth de screens/KDS/recibos (slug
> opaco), sigue siendo necesario. Solo se elimina el fallback api_key/sesión de `apiMiddleware()`.

**1.3 — `panel/API/lib/admin_auth.php`**: reescribir `adminMiddleware()` (el bloque que hace
`jwtDecode` + chequeo `iss/aud`, líneas ~150-170) por:

```php
function adminMiddleware(): void
{
    if (empty($_POST)) {
        $body = file_get_contents('php://input');
        if ($body) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) { $_POST = $decoded; }
        }
    }

    global $db;
    include_once __DIR__ . '/../../includes/db.php';
    require_once __DIR__ . '/../../../app/includes/auth_session.php'; // fuente única en app/includes/

    if (!authResolve(['admin'])) {
        apiUnauthorized('No autorizado (admin)');
    }

    define('ADMIN_AUTHED_ID', AUTHED_USER_ID);

    // Email para auditoría (el token opaco no lo lleva). Lookup barato (tráfico admin mínimo).
    $email = '';
    try {
        $r = $db->Execute('SELECT email FROM admin_user WHERE adminId = ? LIMIT 1', [AUTHED_USER_ID]);
        if ($r && !$r->EOF) { $email = (string)($r->fields['email'] ?? ''); }
    } catch (\Throwable $e) {
        error_log('[adminMiddleware] email lookup falló: ' . $e->getMessage());
    }
    define('ADMIN_AUTHED_EMAIL', $email);
}
```

> Nota: `authResolve(['admin'])` corta 401 por sí mismo si hay token inválido; el `if (!authResolve)`
> cubre el caso sin token. Mantener `adminVerifyPassword`, `adminAudit`, `_adminExtractJwt`
> (este último queda sin uso → borrar en F6).

**Criterio de done:** los 3 middlewares delegan en `authResolve`. Todavía no hay emisores
nuevos → nadie puede loguear con el modelo nuevo. Esperado: con F1 sola, todo da 401 hasta F2-F4.
**Por eso F1-F4 se deployан JUNTAS** (un solo deploy de cutover). Commitear por separado, deploy al cerrar F4.

---

### F2 — Emisor panel + logout panel

**2.1 — `panel/includes/functions.php::issueJwtPanel()`** (líneas 8996-9061): reemplazar el
cuerpo (desde `require_once __DIR__ . '/jwt.php';` hasta el `return`) por:

```php
function issueJwtPanel(array|ArrayAccess $user): array
{
    require_once __DIR__ . '/../../app/includes/auth_session.php'; // fuente única en app/includes/

    $outlet = ncmExecute(
        "SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1",
        [$user['companyId']]
    );

    $ttl = (int)($_ENV['PANEL_JWT_TTL'] ?? 86400); // 24h (sesión interactiva)

    $raw = authSessionCreate('panel', [
        'companyId'  => (string)$user['companyId'],
        'userId'     => (string)$user['contactId'],
        'outletId'   => (string)($outlet['outletId'] ?? ''),
        'roleId'     => (string)(int)$user['role'],
        'module'     => 'panel',
        'expiresAt'  => date('Y-m-d H:i:s', time() + $ttl),
    ]);

    authSetOpaqueCookie('_jwt_panel', $raw, $ttl, 'Lax');

    return ['token' => $raw, 'expiresIn' => $ttl];
}
```

Firma y return IDÉNTICOS → `loginPart()` y el endpoint de login no se tocan. El frontend
(catch-all BFF) tampoco: la cookie `_jwt_panel` sigue HttpOnly y el browser nunca la lee.

**2.2 — `panel/logout.php`** (cierra el gap: hoy NO revoca): reemplazar el archivo entero por:

```php
<?php
    include_once("includes/db.php");
    include_once('includes/simple.config.php');
    include_once("includes/config.php");
    require_once(__DIR__ . "/../app/includes/auth_session.php"); // fuente única en app/includes/

    session_start();

    // Revocar la sesión opaca del panel (server-side) además de destruir la sesión PHP.
    foreach (_authExtractTokens() as $raw) {
        authSessionRevokeByToken($raw);
    }
    authClearCookie('_jwt_panel', 'Lax');

    unset($_SESSION['user']);
    header("Location:/login");
    die("Redirecting");
?>
```

**2.3 — Endpoint `POST /v1/logout` del panel** (frontend lo llama; §42). Localizar el archivo
(`api/v1/logout.php` o `panel/API/v1/logout.php` — `grep -rn "v1/logout\|logout" api/v1 panel/API/v1`).
Asegurar que su cuerpo sea:

```php
<?php
require_once __DIR__ . '/../bootstrap.php'; // o el include de middleware correspondiente
require_once API_APP_DIR . '/includes/auth_session.php';

if (authResolve(['panel'])) {
    authSessionRevokeBySessionId(AUTHED_SESSION_ID, AUTHED_USER_ID);
}
authClearCookie('_jwt_panel', 'Lax');
apiOk(['ok' => true]);
```

> El endpoint real es `api/v1/logout.php` (ya existe) → usa `API_APP_DIR . '/includes/auth_session.php'`.
> Si en algún caso viviera bajo `panel/API/v1/`, el path sería `__DIR__ . '/../../../app/includes/auth_session.php'`
> (fuente única en app/includes/). El BFF catch-all reenvía el `Set-Cookie`.

**Criterio de done:** login de panel crea fila en `auth_session`; logout la revoca + limpia cookie.

---

### F3 — Emisores POS + Screen + SSO + logout POS

**3.1 — `app/API/auth.php`** (login POS): reemplazar el bloque de emisión (desde
`$secret = $_ENV['JWT_SECRET'] ?? '';` hasta el `jwtSetCookie($token, $ttl);`, líneas ~104-126)
por:

```php
require_once __DIR__ . '/../includes/auth_session.php';

$ttl = (int)($_ENV['JWT_TTL'] ?? 0); // POS: 0 = eterno (device pairing)

$raw = authSessionCreate('pos-app', [
    'companyId'  => $companyId,
    'userId'     => $userId,
    'deviceId'   => $deviceId ?: null,
    'outletId'   => $outletId,
    'registerId' => $registerId,
    'roleId'     => (string)(int)$result['role'],
    'module'     => 'pos',
    'expiresAt'  => $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null,
]);

// Legacy /app (browser app.punto.la) aún espera la cookie _jwt. El POS frontend usa Bearer.
authSetOpaqueCookie('_jwt', $raw, $ttl, 'Lax');
$token = $raw;
```

El `echo json_encode([... 'token' => $token ...])` final NO cambia (el cliente guarda `token`
en `localStorage['punto.device.token.pos']` y lo manda Bearer). `deviceRegister()` se mantiene
intacto antes de este bloque.

**3.2 — `app/API/refresh.php`**: con sesiones stateful el refresh es extend-expiry. Reemplazar
todo desde `$ttl = (int)($_ENV['JWT_TTL'] ?? 28800);` (línea ~74) hasta el final por:

```php
require_once __DIR__ . '/../includes/auth_session.php';

$ttl = (int)($_ENV['JWT_TTL'] ?? 0);
$rawIn = _authExtractTokens()[0] ?? '';

if ($rawIn === '') {
    http_response_code(401);
    die(json_encode(['error' => 'Token requerido']));
}

global $db;
if ($ttl > 0) {
    $db->Execute(
        'UPDATE auth_session SET "expiresAt" = ? WHERE "tokenHash" = ? AND realm = \'pos-app\' AND status = 1',
        [date('Y-m-d H:i:s', time() + $ttl), authHashToken($rawIn)]
    );
}
// Si ttl=0 (eterno) no hay nada que renovar — la sesión no expira.
echo json_encode(['token' => $rawIn, 'expires_in' => $ttl]);
```

Borrar el bloque previo de `_jwtExtractTokens`/`jwtDecode`/`jwtEncode` de refresh.php (líneas ~20-72).

**3.3 — `app/API/logout.php`** (POS "desinstalar"): reemplazar el bloque de revocación de device
(el `if ($payload !== null) { ... UPDATE device ... }`, líneas ~30-66) por:

```php
require_once __DIR__ . '/../includes/auth_session.php';

global $db;
foreach (_authExtractTokens() as $raw) {
    // Revocar la sesión + (si tiene device) el device y todas sus sesiones.
    $s = authSessionLookup($raw);
    if ($s !== null && (string)$s['realm'] === 'pos-app') {
        authSessionRevokeByToken($raw);
        $deviceId  = (string)($s['deviceId'] ?? '');
        $companyId = (string)($s['companyId'] ?? '');
        if ($deviceId !== '' && $companyId !== '') {
            try {
                $db->Execute(
                    "UPDATE device SET status = 0, revokedAt = now(), revokedBy = ?
                       WHERE deviceId = ? AND companyId = ?",
                    [(string)($s['userId'] ?? '') ?: null, $deviceId, $companyId]
                );
            } catch (\Throwable $e) {
                error_log('[logout] revoke device falló: ' . $e->getMessage());
            }
            authSessionRevokeByDevice($deviceId, $companyId, (string)($s['userId'] ?? '') ?: null);
        }
    }
}
authClearCookie('_jwt', 'Lax');
echo json_encode(['ok' => true]);
```

**3.4 — Screen (Checkout) pairing**: localizar el endpoint que paréa la pantalla y emite su token
(`grep -rn "screen" api/v1/screens.php` y buscar donde hoy hace `jwtEncode` con `iss=pos-app`/screen).
Reemplazar la emisión por `authSessionCreate('screen', [... 'module'=>'screen', 'deviceId'=>$deviceId,
'expiresAt'=>null ...])` y devolver en el JSON, ADEMÁS del token, los claims que el front decodifica
hoy client-side: `companyId`, `outletId`, `screenId` (y `registerId` si aplica). Shape de respuesta:

```php
echo json_encode([
    'token'      => $raw,
    'companyId'  => $companyId,
    'outletId'   => $outletId,
    'screenId'   => $screenId,
    'registerId' => $registerId ?? '',
]);
```

> Razón: el token opaco NO es decodificable en el cliente. Hoy el screen hace
> `decodeJwtPayload(token)` para sacar companyId/outletId. Eso DEBE reemplazarse (3.6).

**3.5 — SSO handoff legacy (`panel/bff/pos-redirect.php` + `app/handoff.php`)**: el POS productivo
vive en frontend (mismo dominio), pero el link "Caja" → `app.punto.la` (legacy /app) sigue. Reescribir:

`panel/bff/pos-redirect.php` — reemplazar la emisión del token 15s (`jwtEncode([... 'exp'=>$_now+15])`)
por una sesión pos-app corta de un solo salto:

```php
require_once __DIR__ . '/../../app/includes/auth_session.php'; // panel/bff/ → app/includes/

if (!authResolve(['panel'])) { header('Location: /login'); exit; }

$_registerResult = $db->Execute(
    'SELECT registerId FROM register WHERE outletId = ? ORDER BY registerId ASC LIMIT 1',
    [AUTHED_OUTLET_ID]
);
$_registerId = ($_registerResult && !$_registerResult->EOF)
    ? (string)($_registerResult->fields['registerId'] ?? '') : '';

$hopRaw = authSessionCreate('pos-app', [
    'companyId'  => AUTHED_COMPANY_ID,
    'userId'     => AUTHED_USER_ID,
    'outletId'   => AUTHED_OUTLET_ID,
    'registerId' => $_registerId,
    'roleId'     => AUTHED_ROLE_ID,
    'module'     => 'pos',
    'expiresAt'  => date('Y-m-d H:i:s', time() + 60), // hop de 60s
    'meta'       => ['sso_hop' => true],
]);

$_posBase = defined('APP_URL') && APP_URL ? APP_URL : (defined('POS_URL') ? POS_URL : '');
if ($_posBase === '') { http_response_code(500); die('pos-redirect: APP_URL no configurada'); }
header('Location: ' . $_posBase . '/handoff.php?t=' . rawurlencode($hopRaw));
exit;
```

`app/handoff.php` — reemplazar todo el bloque de validación JWT + re-emisión por: validar el hop,
re-emitir una sesión eterna, revocar el hop, setear `_jwt`:

```php
require_once(__DIR__ . '/includes/auth_session.php');

$hopRaw = $_GET['t'] ?? '';
if ($hopRaw === '') { http_response_code(400); die('handoff: token requerido'); }

$s = authSessionLookup($hopRaw);
if ($s === null || (string)$s['realm'] !== 'pos-app' || (int)$s['status'] !== 1) {
    http_response_code(401); die('handoff: token inválido');
}
$exp = (string)($s['expiresAt'] ?? '');
if ($exp !== '' && strtotime($exp) < time()) { http_response_code(401); die('handoff: token expirado'); }

$ttl = (int)($_ENV['JWT_TTL'] ?? 0);
$raw = authSessionCreate('pos-app', [
    'companyId'  => (string)($s['companyId'] ?? ''),
    'userId'     => (string)($s['userId'] ?? ''),
    'outletId'   => (string)($s['outletId'] ?? ''),
    'registerId' => (string)($s['registerId'] ?? ''),
    'roleId'     => (string)($s['roleId'] ?? ''),
    'deviceId'   => (string)($s['deviceId'] ?? '') ?: null,
    'module'     => 'pos',
    'expiresAt'  => $ttl > 0 ? date('Y-m-d H:i:s', time() + $ttl) : null,
]);
authSessionRevokeByToken($hopRaw); // single-use
authSetOpaqueCookie('_jwt', $raw, $ttl, 'Lax');

$i = base64_encode((string)($s['companyId'] ?? '') . ',' . (string)($s['outletId'] ?? ''));
header('Location: /?i=' . rawurlencode($i));
exit;
```

**3.6 — Frontend screen** (`frontend/app/(screen)/checkout/page.tsx`): reemplazar el uso de
`decodeJwtPayload(token)` por la lectura de los claims que ahora devuelve el pairing (3.4),
persistidos en localStorage junto al token. Agregar a `lib/auth/device-token.ts` (o un helper
adyacente) el guardado de un blob de claims namespaced por module:

```ts
// lib/auth/device-claims.ts (NUEVO)
import type { DeviceModule } from "@/lib/auth/device-token"

export interface DeviceClaims {
  companyId: string
  outletId: string
  screenId?: string
  registerId?: string
}

const KEY = "punto.device.claims"

export function getDeviceClaims(module: DeviceModule = "pos"): DeviceClaims | null {
  if (typeof window === "undefined") return null
  const raw = window.localStorage.getItem(`${KEY}.${module}`)
  return raw ? (JSON.parse(raw) as DeviceClaims) : null
}
export function setDeviceClaims(claims: DeviceClaims, module: DeviceModule = "pos"): void {
  if (typeof window === "undefined") return
  window.localStorage.setItem(`${KEY}.${module}`, JSON.stringify(claims))
}
export function clearDeviceClaims(module: DeviceModule = "pos"): void {
  if (typeof window === "undefined") return
  window.localStorage.removeItem(`${KEY}.${module}`)
}
```

En el handler de pairing del screen: tras recibir `{token, companyId, outletId, screenId}`,
llamar `setDeviceToken(token, "screen")` + `setDeviceClaims({companyId, outletId, screenId}, "screen")`.
Reemplazar todo `decodeJwtPayload(token)` por `getDeviceClaims("screen")`. En el `revoked`/401 del
screen, llamar también `clearDeviceClaims("screen")` junto a `clearDeviceToken("screen")`.

> Aplicar el mismo `setDeviceClaims(..., "pos")` en el pairing del POS si en algún punto el POS
> decodifica el token client-side (auditar `decodeJwtPayload` en frontend; si solo lo usa el
> screen, el POS no necesita claims porque `/api/pos/bootstrap` ya le da el contexto).

**Criterio de done:** POS y Screen paréan → fila `auth_session` (realm pos-app/screen);
Bearer opaco resuelve; logout POS revoca device+sesiones; SSO legacy funciona single-use.

---

### F4 — Emisor admin + logout admin

**4.1 — `panel/API/lib/admin_auth.php::adminIssueJwt()`**: reemplazar el cuerpo por:

```php
function adminIssueJwt($admin): string
{
    require_once __DIR__ . '/../../../app/includes/auth_session.php'; // panel/API/lib/ → app/includes/
    $ttl = (int)($_ENV['ADMIN_JWT_TTL'] ?? 28800); // 8h
    return authSessionCreate('admin', [
        'companyId' => null,                       // cross-tenant
        'userId'    => (string)$admin['adminId'],
        'roleId'    => 'admin',
        'module'    => 'admin',
        'expiresAt' => date('Y-m-d H:i:s', time() + $ttl),
        'meta'      => ['email' => (string)$admin['email']],
    ]);
}
```

El BFF `frontend/app/api/admin/[...path]/route.ts` ya toma `data.token` del login y setea
`_jwt_admin` (TTL `ADMIN_JWT_TTL`) — NO cambia (recibe el token opaco igual que antes).

**4.2 — Logout admin con revocación server-side.** Hoy `panel/bff/admin/logout.php` (legacy) y el
catch-all `route.ts` (frontend) solo limpian la cookie local. Agregar revocación:

- En `frontend/app/api/admin/[...path]/route.ts`, en el branch `if (req.method === "POST" && tail === "logout")`,
  ANTES de limpiar la cookie, reenviar la revocación al backend. Reemplazar ese branch por:

```ts
  if (req.method === "POST" && tail === "logout") {
    const cookieStore = await cookies()
    const adminToken = cookieStore.get("_jwt_admin")?.value ?? ""
    if (adminToken) {
      try {
        await fetch(`${getPanelBase()}/API/v1/admin/logout.php`, {
          method: "POST",
          headers: { Cookie: `_jwt_admin=${adminToken}` },
          cache: "no-store",
        })
      } catch {}
    }
    const res = NextResponse.json({ ok: true })
    res.cookies.set("_jwt_admin", "", {
      path: "/", httpOnly: true, sameSite: "lax", secure: isHttps(req), maxAge: 0,
    })
    return res
  }
```

- Crear/asegurar `panel/API/v1/admin/logout.php`:

```php
<?php
require_once __DIR__ . '/../../lib/admin_auth.php';
require_once __DIR__ . '/../../../../app/includes/auth_session.php'; // panel/API/v1/admin/ → app/includes/

adminMiddleware(); // resuelve + define ADMIN_AUTHED_ID; corta 401 si no hay sesión válida
if (defined('AUTHED_SESSION_ID')) {
    authSessionRevokeBySessionId(AUTHED_SESSION_ID, AUTHED_USER_ID);
}
apiOk(['ok' => true]);
```

**Criterio de done:** login admin crea sesión realm=admin; logout la revoca server-side.
**→ Deploy de cutover aquí (F1+F2+F3+F4 juntas). Todos re-loguean una vez.**

---

### F5 — Revocación desde el panel (la capacidad que pediste)

**5.1 — Devices: revocar también las sesiones.** Localizar el handler DELETE de `api/v1/devices.php`
(usado por `useRevokePosDevice` → `DELETE /v1/devices?id=<deviceId>`). Donde hoy hace
`UPDATE device SET status=0`, agregar inmediatamente después:

```php
require_once API_APP_DIR . '/includes/auth_session.php';
authSessionRevokeByDevice($deviceId, AUTHED_COMPANY_ID, AUTHED_USER_ID);
```

> Así, revocar un dispositivo desde la UI existente corta sus sesiones al instante
> (en vez de esperar el TTL 60s del file-cache, que se elimina en F6).

**5.2 — Endpoint nuevo `api/v1/sessions.php`** — lista + revoca sesiones del tenant (panel + POS + screen):

```php
<?php
require_once __DIR__ . '/../bootstrap.php';
$ctx = apiAuthTenant(['panel']); // solo el panel gestiona sesiones
require_once API_APP_DIR . '/includes/auth_session.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
global $db;

if ($method === 'GET') {
    $showRevoked = ($_GET['showRevoked'] ?? '') === '1';
    $where = '"companyId" = ?' . ($showRevoked ? '' : ' AND status = 1');
    $r = $db->Execute(
        'SELECT "sessionId", realm, "userId", "deviceId", "outletId", "registerId", module,
                status, "createdAt", "lastSeenAt", "expiresAt", "revokedAt", "ipLast", "userAgent"
           FROM auth_session WHERE ' . $where . ' ORDER BY "lastSeenAt" DESC NULLS LAST LIMIT 500',
        [$ctx['companyId']]
    );
    $rows = [];
    while ($r && !$r->EOF) { $rows[] = $r->fields; $r->MoveNext(); }
    apiOk(['sessions' => $rows]);
}

if ($method === 'DELETE') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
        apiError('sessionId inválido', 422);
    }
    // Scope de tenant (§1): solo sesiones de la propia empresa.
    $chk = $db->Execute('SELECT 1 FROM auth_session WHERE "sessionId" = ? AND "companyId" = ? LIMIT 1', [$id, $ctx['companyId']]);
    if (!$chk || $chk->EOF) { apiError('Sesión no encontrada', 404); }
    authSessionRevokeBySessionId($id, $ctx['userId']);
    apiOk(['ok' => true]);
}

apiError('Método no permitido', 405);
```

Registrar el entity en el mapa `realtimeAfterMutation` de `api/bootstrap.php` (opcional):
`'/v1/sessions' => ['entity' => 'session', 'scope' => 'all'],`.

**5.3 — UI `frontend/app/(panel)/settings/sessions/page.tsx`** + hook `hooks/use-sessions.ts`.
DataTable (§ convenciones: `<DataTable>` reusable) con columnas: realm/módulo, usuario, outlet,
última actividad, IP, estado; acción revocar. Hook:

```ts
// hooks/use-sessions.ts
"use client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface AuthSession {
  sessionId: string
  realm: string
  userId: string | null
  deviceId: string | null
  outletId: string | null
  module: string | null
  status: number
  createdAt: string | null
  lastSeenAt: string | null
  expiresAt: string | null
  ipLast: string | null
  userAgent: string | null
}

export function useSessions(opts: { showRevoked?: boolean } = {}) {
  const qs = opts.showRevoked ? "?showRevoked=1" : ""
  return useQuery<AuthSession[]>({
    queryKey: ["auth-sessions", { showRevoked: !!opts.showRevoked }],
    queryFn: async () => {
      const res = await api.get<{ sessions: AuthSession[] }>(`/v1/sessions${qs}`)
      return res.sessions ?? []
    },
    staleTime: 30_000,
  })
}

export function useRevokeSession() {
  const qc = useQueryClient()
  return useMutation<{ ok: boolean }, Error, string>({
    mutationFn: (sessionId) => api.del<{ ok: boolean }>(`/v1/sessions?id=${sessionId}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["auth-sessions"] }),
  })
}
```

La page sigue el patrón de `settings/devices/page.tsx` (mismo layout, DataTable, confirm dialog
de revocación). Agregar el link en el sidebar de Settings gateado por permiso admin del tenant.

**Criterio de done:** desde el panel se listan y revocan TODAS las sesiones (panel/POS/screen) de
la empresa; revocar un device también corta sus sesiones.

---

### F6 — Rip-out del JWT (la limpieza que cierra la deuda)

Solo cuando F1-F5 estén verificadas en prod. Borrar:

1. `app/includes/jwt_middleware.php`: eliminar `jwtIsDeviceRevoked()`, el file-cache
   (`punto_device_status`), `jwtInvalidateDeviceCache()`, `jwtSetCookie()`, `_jwtExtractTokens()`,
   `_jwtExtractToken()`. Dejar SOLO el wrapper `jwtAuthenticate()` (que llama `authResolve`), o
   renombrar call-sites a `authResolve` y borrar el archivo entero (preferible — `api/bootstrap.php`
   y `app/*` pasan a `require auth_session.php` + `authResolve`).
2. `app/includes/jwt.php`: si nada más emite/decodifica JWT (verificar con
   `grep -rn "jwtEncode\|jwtDecode\|_jwtB64" app api panel --include=*.php`), borrarlo.
   **OJO**: `_jwtB64Decode` lo usaba `app/handoff.php` viejo — ya reescrito en F3.5, no lo usa.
3. `panel/API/lib/admin_auth.php`: borrar `_adminExtractJwt()` (sin uso tras F1.3).
4. `panel/API/lib/api_middleware.php`: borrar el `require_once .../jwt.php` y `_apiExtractJwtToken()`
   si quedaron sin uso.
5. Env vars: `JWT_SECRET`, `JWT_TTL`, `PANEL_JWT_TTL`, `ADMIN_JWT_SECRET`, `ADMIN_JWT_TTL` siguen
   usándose como TTLs (reusados por `authSessionCreate`). **NO** borrar `*_TTL`. `JWT_SECRET` y
   `ADMIN_JWT_SECRET` quedan sin uso → marcar como deprecadas en `.env.example` (no romper prod;
   borrar en un commit posterior).
6. Actualizar `context/02-arquitectura.md` (tabla de realms), `context/08-convenciones-criticas.md`
   (§12.1, §28, §33, §42 — el `iss` ya no es barrera criptográfica; el realm es columna). Delegar
   a Sonnet vía `/end-session` o un brief de docs.

**Criterio de done:** cero `jwtEncode/jwtDecode` en el codebase; un solo modelo de auth.

---

### F7 — (Opcional) Cache Redis del resolver

Activar solo si se mide latencia. Reemplazar los stubs no-op de `auth_session.php` por la
implementación real (mismo patrón `fsockopen`+RESP de `app/includes/ws_publish.php`, con lectura
de reply). Gated por `AUTH_SESSION_CACHE=1`. Key `punto:authsess:<tokenHash>`, TTL 300s, valor =
JSON de la fila (o tombstone `__none__`). `_authCacheDel` se llama en cada revoke (ya cableado).

```php
function _authRedisConn()
{
    $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
    $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
    $pass = null; $user = null;
    if (!empty($_ENV['REDIS_URL'])) {
        $ru = parse_url((string)$_ENV['REDIS_URL']);
        $host = $ru['host'] ?? $host;
        $port = (int)($ru['port'] ?? $port);
        $user = $ru['user'] ?? null;
        $pass = isset($ru['pass']) ? urldecode($ru['pass']) : null;
    }
    $sock = @fsockopen($host, $port, $e, $s, 1);
    if (!$sock) { return null; }
    stream_set_timeout($sock, 1);
    if ($pass !== null) {
        $auth = ($user && $user !== 'default')
            ? _authResp('AUTH', $user, $pass) : _authResp('AUTH', $pass);
        fwrite($sock, $auth);
        $r = fgets($sock);
        if ($r === false || strpos($r, '+OK') !== 0) { fclose($sock); return null; }
    }
    return $sock;
}
function _authResp(string ...$p): string
{
    $o = '*' . count($p) . "\r\n";
    foreach ($p as $x) { $o .= '$' . strlen($x) . "\r\n" . $x . "\r\n"; }
    return $o;
}
function _authRedisReadBulk($sock)
{
    $line = fgets($sock);
    if ($line === false) { return null; }
    $line = rtrim($line, "\r\n");
    if ($line === '$-1' || $line[0] !== '$') { return null; }
    $len = (int)substr($line, 1);
    $data = '';
    while (strlen($data) < $len) {
        $chunk = fread($sock, $len - strlen($data));
        if ($chunk === false || $chunk === '') { break; }
        $data .= $chunk;
    }
    fread($sock, 2); // CRLF
    return $data;
}
function _authCacheGet(string $hash)
{
    $sock = _authRedisConn();
    if (!$sock) { return null; } // miss → DB
    fwrite($sock, _authResp('GET', 'punto:authsess:' . $hash));
    $val = _authRedisReadBulk($sock);
    fclose($sock);
    if ($val === null) { return null; }
    if ($val === '__none__') { return false; } // tombstone
    $row = json_decode($val, true);
    return is_array($row) ? $row : null;
}
function _authCacheSet(string $hash, $row): void
{
    $sock = _authRedisConn();
    if (!$sock) { return; }
    $json = json_encode($row instanceof ArrayAccess ? iterator_to_array($row) : (array)$row, JSON_UNESCAPED_UNICODE);
    fwrite($sock, _authResp('SETEX', 'punto:authsess:' . $hash, '300', $json));
    fgets($sock); fclose($sock);
}
function _authCacheSetTombstone(string $hash): void
{
    $sock = _authRedisConn();
    if (!$sock) { return; }
    fwrite($sock, _authResp('SETEX', 'punto:authsess:' . $hash, '60', '__none__'));
    fgets($sock); fclose($sock);
}
function _authCacheDel(string $hash): void
{
    if ($hash === '') { return; }
    $sock = _authRedisConn();
    if (!$sock) { return; }
    fwrite($sock, _authResp('DEL', 'punto:authsess:' . $hash));
    fgets($sock); fclose($sock);
}
```

> Al castear la fila para cache: `authSessionLookup` devuelve CaseInsensitiveArray. En
> `_authCacheSet` se serializa a array plano; al releer de cache vuelve array plano con keys
> camelCase exactas (el SELECT las alias con quotes) → `authResolve` accede igual. Verificar
> que las keys del JSON cacheado coincidan con las que lee `authResolve` (sessionId, status,
> realm, expiresAt, etc.).

---

## REVISIÓN MODERNA (F2/F3/F4 reales) — 2026-06-29

Superficie real que frontend usa. Devices = realm **`pos-app`** + columna **`module`**
(`pos`/`screen`/`kds`/`display`) — NO realm separado por tipo. `auth_session.php` se requiere
desde `api/lib/Auth/` con `dirname(__DIR__, 2) . '/../app/includes/auth_session.php'` (mismo
patrón que el `require` de `jwt.php` que ya tienen esos archivos).

### F2r — Sesión de panel: `api/lib/Auth/PanelAuth.php::issueJwt()`

Reemplazar el bloque `require_once .../jwt.php` + `jwtEncode([...])` + `setcookie(...)` (todo
desde el require de jwt.php hasta el `setcookie('_jwt_panel', ...)`) por:

```php
        require_once dirname(__DIR__, 2) . '/../app/includes/auth_session.php';

        if ($outletIdOverride !== null) {
            $resolvedOutletId = $outletIdOverride;
        } else {
            $outlet = ncmExecute(
                'SELECT outletId FROM outlet WHERE companyId = ? AND outletStatus = 1 ORDER BY outletId ASC LIMIT 1',
                [$user['companyId']]
            );
            $resolvedOutletId = (string) ($outlet['outletId'] ?? '');
        }

        $ttl = (int) ($_ENV['PANEL_JWT_TTL'] ?? 86400);

        $raw = authSessionCreate('panel', [
            'companyId' => (string) $user['companyId'],
            'userId'    => (string) $user['contactId'],
            'outletId'  => $resolvedOutletId,
            'roleId'    => (string) ($user['role'] ?? ''),
            'module'    => 'panel',
            'expiresAt' => date('Y-m-d H:i:s', time() + $ttl),
        ]);

        authSetOpaqueCookie('_jwt_panel', $raw, $ttl, 'Lax');

        return ['token' => $raw, 'expiresIn' => $ttl];
```

> El `$secret = $_ENV['JWT_SECRET']` / `if ($secret === '') return [...]` del inicio se elimina
> (ya no se firma nada). Firma y return idénticos → `login.php`, `active-outlet.php`, `signup.php`
> no se tocan. Cookie name `_jwt_panel` sin cambiar.

### F3r — Device: `api/lib/Auth/DeviceAuth.php`

**(a) `buildToken()`** — reemplazar el cuerpo (`$now = time(); return jwtEncode([...]);`) por:

```php
        require_once dirname(__DIR__, 2) . '/../app/includes/auth_session.php';
        // Device = sesión opaca eterna (expiresAt null), revocable por sesión o por device.status.
        // oid/rid/module se guardan info-only; el backend resuelve scope desde la fila device.
        return authSessionCreate('pos-app', [
            'companyId'  => $companyId,
            'userId'     => $pairedByContactId,
            'deviceId'   => $deviceId,
            'outletId'   => $outletId,
            'registerId' => $registerId,
            'roleId'     => '1',
            'module'     => $module,
            'expiresAt'  => null,
        ]);
```

El parámetro `$secret` queda sin uso (no borrar la firma — la llaman `issueToken`/`createDeviceAndIssueJwt`).
Los `require_once .../jwt.php` y los checks `$secret === ''` de `issueJwt`/`createDeviceAndIssueJwt`/
`issueJwtForExistingDevice` pueden quedar (inofensivos) o borrarse — NO bloquean.

**(b) `validateJwt()`** — reemplazar el cuerpo completo por lookup de sesión opaca + fila device
como fuente de verdad (preserva revocación por `device.status` Y por `auth_session.status`):

```php
    public static function validateJwt(string $bearerToken): ?array
    {
        require_once dirname(__DIR__, 2) . '/../app/includes/auth_session.php';

        $s = authSessionLookup($bearerToken);
        if ($s === null
            || (int) $s['status'] !== 1
            || (string) $s['realm'] !== 'pos-app') {
            return null;
        }
        $exp = (string) ($s['expiresAt'] ?? '');
        if ($exp !== '' && strtotime($exp) < time()) {
            return null;
        }
        $deviceId = (string) ($s['deviceId'] ?? '');
        if ($deviceId === '') {
            return null;
        }

        // Fila device = fuente de verdad (outlet/register/module pueden cambiar post-pairing).
        $device = ncmExecute(
            'SELECT deviceid, companyid, outletid, registerid, userid, module FROM device WHERE deviceid = ?::uuid AND companyid = ?::uuid AND status = 1',
            [$deviceId, (string) ($s['companyId'] ?? '')]
        );
        if (!$device) {
            return null;
        }

        try {
            ncmExecute(
                'UPDATE device SET lastseenat = now(), iplast = ?::inet WHERE deviceid = ?::uuid',
                [$_SERVER['REMOTE_ADDR'] ?? null, $deviceId]
            );
        } catch (\Throwable) {
            // best-effort
        }

        $module = (string) ($device['module'] ?? $s['module'] ?? 'pos');
        return [
            'companyId'  => (string) ($device['companyid']  ?? ''),
            'outletId'   => (string) ($device['outletid']   ?? ''),
            'registerId' => (string) ($device['registerid'] ?? ''),
            'deviceId'   => $deviceId,
            'userId'     => (string) ($device['userid']     ?? ''),
            'roleId'     => '1',
            'isDevice'   => true,
            'module'     => $module,
        ];
    }
```

**(c) `issueJwtForExistingDevice()`** — el `return` final debe incluir `companyId`/`registerId`/`outletId`
(el screen los necesita para los canales WS, ya que no puede decodificar el token opaco). Cambiar:

```php
        return [
            'deviceId'   => $deviceId,
            'token'      => $token,
            'expiresIn'  => self::TTL,
            'companyId'  => (string) ($device['companyid']  ?? $companyId ?? ''),
            'registerId' => (string) ($device['registerid'] ?? ''),
            'outletId'   => (string) ($device['outletid']   ?? ''),
        ];
```

### F3r — `api/lib/services/DeviceInvitationService.php`: exponer cid/rid al device

El device recibe su token por **polling de `status()`** (y por `open()` en auto-approve). Esos
responses deben incluir `companyId` + `registerId` para que el screen arme los canales WS.

**`status()`** — en la rama `if ($status === 'approved')`, agregar al `$result`:

```php
                $result['companyId']  = (string) ($jwt['companyId']  ?? '');
                $result['registerId'] = (string) ($jwt['registerId'] ?? '');
```

**`open()`** — en la rama `if ($autoApprove)`, el array de retorno suma:

```php
                'companyId'  => (string) ($issued['companyId']  ?? ''),
                'registerId' => (string) ($issued['registerId'] ?? ''),
```

(`$issued = issueJwtForExistingDevice(...)` ya devuelve esos campos por el cambio (c).)

### F3r — Frontend del screen (frontend)

**Nuevo `frontend/lib/auth/device-claims.ts`** (claims que el token opaco ya no expone):

```ts
import type { DeviceModule } from "@/lib/auth/device-token"

export interface DeviceClaims {
  companyId: string
  registerId: string
  deviceId: string
}

const KEY = "punto.device.claims"

export function getDeviceClaims(module: DeviceModule = "pos"): DeviceClaims | null {
  if (typeof window === "undefined") return null
  const raw = window.localStorage.getItem(`${KEY}.${module}`)
  try { return raw ? (JSON.parse(raw) as DeviceClaims) : null } catch { return null }
}
export function setDeviceClaims(claims: DeviceClaims, module: DeviceModule = "pos"): void {
  if (typeof window === "undefined") return
  window.localStorage.setItem(`${KEY}.${module}`, JSON.stringify(claims))
}
export function clearDeviceClaims(module: DeviceModule = "pos"): void {
  if (typeof window === "undefined") return
  window.localStorage.removeItem(`${KEY}.${module}`)
}
```

**`connect-view.tsx`** — al persistir el token (ambos paths: `autoApproveToken` y polling
`approved`), persistir también los claims. El response ahora trae `companyId`/`registerId`/`deviceId`:
- En el `useEffect` de `autoApproveToken`: el `open()` server-side ya devolvió esos campos →
  pasarlos por props (`autoApproveClaims`) o leerlos del mismo `openResult`. Tras
  `setDeviceToken(autoApproveToken, mod)` agregar
  `setDeviceClaims({ companyId, registerId, deviceId }, mod)`.
- En el polling: el body de `status` ahora trae `data.companyId`/`data.registerId`/`data.deviceId`.
  Tras `setDeviceToken(tokenFromBody, mod)` agregar
  `setDeviceClaims({ companyId: data.companyId, registerId: data.registerId, deviceId: data.deviceId }, mod)`.
  Ajustar el tipo del `data` para incluir esos campos.
- En `page.tsx` (server) de `/connect/[id]`: propagar `companyId`/`registerId`/`deviceId` del
  `openResult` (auto-approve) a `ConnectView` como props.

**`checkout/page.tsx`** — eliminar `decodeJwtPayload` y leer del store:
- Reemplazar `const claims = decodeJwtPayload(token)` + `claims["cid"]/["rid"]/["did"]` por
  `const claims = getDeviceClaims("screen")` y usar `claims?.companyId / claims?.registerId / claims?.deviceId`.
- En el path de error (cuando faltan cid/rid) agregar `clearDeviceClaims("screen")` junto a
  `clearDeviceToken("screen")`.
- Borrar la función `decodeJwtPayload` (queda sin uso).

**`frontend/app/api/pos/revoke-this-device/route.ts`** — hoy hace `atob` del token para sacar
el `deviceId`. Con token opaco no se puede decodificar server-side. Cambiar para que el cliente
mande el `deviceId` en el body (lo tiene en `getDeviceClaims("pos")?.deviceId`), y el route lo
reenvíe al backend de revocación. Si el caller del route no tiene el deviceId disponible, leerlo
de `getDeviceClaims("pos")` antes de invocar el route. (Persistir también claims `pos` en
connect-view para el módulo pos, no solo screen.)

> Para que el POS tenga sus claims: en `connect-view.tsx` el `setDeviceClaims(..., "pos")` aplica
> igual cuando `module === "pos"` (el response trae los mismos campos). Así `revoke-this-device`
> los encuentra.

### F4r — Impersonación admin: `CompanyAdminService::getEnterToken()`

Reemplazar el `require_once .../jwt.php` + `jwtEncode([...])` por sesión opaca panel (el admin
entra como el dueño del tenant). `getEnterToken` vive en `panel/lib/admin/` → path a auth_session:

```php
        require_once __DIR__ . '/../../../app/includes/auth_session.php';

        $ttl = (int) ($_ENV['JWT_TTL'] ?? 28800);
        $raw = authSessionCreate('panel', [
            'companyId' => (string) ($cf['companyid'] ?? ''),
            'userId'    => (string) ($cf['contactid'] ?? ''),
            'outletId'  => $outletId,
            'roleId'    => (string) ((int) ($cf['role'] ?? 1)),
            'module'    => 'panel',
            'expiresAt' => date('Y-m-d H:i:s', time() + $ttl),
        ]);

        return ['token' => $raw, 'expiresIn' => $ttl];
```

El BFF admin (`frontend/app/api/admin/[...path]/route.ts`, rama `action=enter`) ya toma
`data.token` y setea cookie `_jwt_panel` — sin cambios.

### F4r — Admin propio: `adminIssueJwt()`

Igual que la F4 vieja del doc (la función `adminIssueJwt` en `panel/API/lib/admin_auth.php`
→ `authSessionCreate('admin', ...)`), con el path `__DIR__ . '/../../../app/includes/auth_session.php'`.

### Pendiente menor (no bloquea cutover)

- **`panel/crons/cronCreateRecurringInvoice.php`**: mintea un service token `pos-app` (cookie `_jwt`,
  2min) para re-postear a `action.php` LEGACY. Como `action.php` es código muerto, este path es
  deuda pre-existente — se resuelve cuando el cron de recurrentes se migre a SaleService. No tocar
  en este rewrite.
- **Acumulación de sesiones por device**: cada re-pairing crea una fila `auth_session` nueva (la
  vieja sigue válida hasta revocar el device). Aceptable; dedupe opcional a futuro.

---

## Resumen de archivos tocados

| Fase | Archivos |
|------|----------|
| F0 | `database/migrations/postgres/69_auth_session.sql` (nuevo), `app/includes/auth_session.php` (nuevo) |
| F1 | `app/includes/jwt_middleware.php`, `panel/API/lib/api_middleware.php`, `panel/API/lib/admin_auth.php` |
| F2 | `panel/includes/functions.php` (`issueJwtPanel`), `panel/logout.php`, `api/v1/logout.php` (o panel equiv) |
| F3 | `app/API/auth.php`, `app/API/refresh.php`, `app/API/logout.php`, screen pairing endpoint, `panel/bff/pos-redirect.php`, `app/handoff.php`, `frontend/app/(screen)/checkout/page.tsx`, `frontend/lib/auth/device-claims.ts` (nuevo) |
| F4 | `panel/API/lib/admin_auth.php` (`adminIssueJwt`), `frontend/app/api/admin/[...path]/route.ts`, `panel/API/v1/admin/logout.php` |
| F5 | `api/v1/devices.php` (DELETE), `api/v1/sessions.php` (nuevo), `frontend/hooks/use-sessions.ts` (nuevo), `frontend/app/(panel)/settings/sessions/page.tsx` (nuevo) |
| F6 | borrado de `jwt.php`/`jwt_middleware.php` residual + docs |
| F7 | `app/includes/auth_session.php` (cache real) |

## Invariantes a preservar (checklist de code-reviewer por fase)

- §1 aislamiento: toda query de sesiones filtra por `companyId` (excepto admin cross-tenant, marcado).
- Nunca guardar el token crudo en DB — solo `sha256`.
- `authResolve` descarta candidatos de otro realm/revocados; solo mata 401 si NINGUNO sobrevive.
- Cookies: panel/admin HttpOnly; nombres `_jwt_panel`/`_jwt_admin`/`_jwt` sin cambiar.
- Cutover: F1-F4 deployan juntas (entre medio, login está roto por diseño).
- §44: `auth_session` es tabla nueva → camelCase con quotes en TODO el SQL.

## Changelog
- 2026-06-29 — plan creado (Opus). Pendiente ejecución F0.
