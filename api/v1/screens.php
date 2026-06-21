<?php
/**
 * /v1/screens — Checkout Screen: device pairing + heartbeat + publish.
 *
 *   POST ?resource=request   (no auth) — genera PIN + espera pairing vía WS
 *   POST ?resource=heartbeat (auth screen JWT) — keep-alive del dispositivo
 *   POST ?resource=pair      (auth pos-app) — empareja pantalla con el PIN
 *   POST ?resource=publish   (auth pos-app) — emite evento al canal de la caja
 *   GET  (sin resource)      (auth panel)  — lista pantallas del tenant
 *   DELETE ?id=<uuid>        (auth panel)  — revoca (soft-delete) una pantalla
 */

require_once __DIR__ . '/../bootstrap.php';

// ── Redis helper ─────────────────────────────────────────────────────────────
//
// NO usamos la extensión `phpredis` (no está disponible en el container PHP de
// Punto). Mismo approach que `app/includes/ws_publish.php`: socket TCP + RESP
// nativo, soporta REDIS_URL al estilo Coolify (redis://[user:pass@]host:port).

/** Devuelve [host, port, pass] desde REDIS_URL o variables sueltas. */
function screensRedisConfig(): array
{
    if (!empty($_ENV['REDIS_URL'])) {
        $ru   = parse_url((string) $_ENV['REDIS_URL']);
        $host = $ru['host'] ?? '127.0.0.1';
        $port = (int) ($ru['port'] ?? 6379);
        $pass = isset($ru['pass']) ? urldecode($ru['pass']) : '';
    } else {
        $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
        $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
        $pass = (string) ($_ENV['REDIS_PASSWORD'] ?? '');
    }
    return [$host, $port, $pass];
}

/** Construye un comando RESP (mismo helper que ws_publish.php). */
function screensRedisRespCmd(string ...$parts): string
{
    $out = '*' . count($parts) . "\r\n";
    foreach ($parts as $part) {
        $out .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
    }
    return $out;
}

/** Lee una reply RESP del socket. Devuelve string|int|null. */
function screensRedisReadReply($sock): mixed
{
    $line = fgets($sock);
    if ($line === false) return null;
    $type    = $line[0];
    $payload = substr($line, 1, -2); // sin el \r\n
    switch ($type) {
        case '+': return $payload;            // simple string ("OK")
        case '-': return null;                // error
        case ':': return (int) $payload;      // integer (INCR, DEL)
        case '$':                              // bulk string (GET)
            $len = (int) $payload;
            if ($len < 0) return null;        // nil
            $data = '';
            while (strlen($data) < $len) {
                $chunk = fread($sock, $len - strlen($data));
                if ($chunk === false || $chunk === '') break;
                $data .= $chunk;
            }
            fread($sock, 2);                  // \r\n trailing
            return $data;
        default: return null;
    }
}

/**
 * Ejecuta UN comando Redis sobre un socket nuevo. Mantener simple — los
 * endpoints de screens no son hot-path; el overhead de abrir socket por
 * comando es despreciable contra la robustez de no mantener estado.
 *
 * Devuelve null si Redis no responde (caller maneja con apiError 503).
 */
function screensRedisCmd(string ...$args): mixed
{
    [$host, $port, $pass] = screensRedisConfig();
    $errno = 0; $errstr = '';
    $sock  = @fsockopen($host, $port, $errno, $errstr, 2);
    if (!$sock) {
        error_log("[screens] No se pudo conectar a Redis {$host}:{$port} — {$errstr}");
        return null;
    }
    stream_set_timeout($sock, 2);

    if ($pass !== '') {
        fwrite($sock, screensRedisRespCmd('AUTH', $pass));
        $authReply = screensRedisReadReply($sock);
        if ($authReply !== 'OK') {
            error_log('[screens] AUTH falló contra Redis');
            fclose($sock);
            return null;
        }
    }

    fwrite($sock, screensRedisRespCmd(...$args));
    $reply = screensRedisReadReply($sock);
    fclose($sock);
    return $reply;
}

// ── Routing sin auth ─────────────────────────────────────────────────────────

$resource = $_GET['resource'] ?? null;
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;

// ── POST ?resource=request — sin auth ────────────────────────────────────────

if ($method === 'POST' && $resource === 'request') {
    $ip  = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip  = trim(explode(',', $ip)[0]);

    // Rate-limit: max 10 requests / 60s por IP
    $rateKey = 'screen:rate:' . $ip;
    $count   = screensRedisCmd('INCR', $rateKey);
    if ($count === null) {
        apiError('Servicio no disponible', 503);
    }
    if ((int)$count === 1) {
        screensRedisCmd('EXPIRE', $rateKey, '60');
    }
    if ((int)$count > 10) {
        apiError('Demasiados intentos. Espera un momento.', 429);
    }

    // Generar PIN de 6 dígitos
    $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Guardar en Redis 5 minutos
    $setexReply = screensRedisCmd(
        'SETEX',
        'screen:pin:' . $pin,
        '300',
        (string) json_encode(['ip' => $ip, 'requestedAt' => time()])
    );
    if ($setexReply === null) {
        apiError('Servicio no disponible', 503);
    }

    apiOk(['pin' => $pin, 'channel' => 'pairing:' . $pin]);
    exit;
}

// ── POST ?resource=heartbeat — auth screen JWT ────────────────────────────────

if ($method === 'POST' && $resource === 'heartbeat') {
    // Leer token desde header Authorization: Bearer <token> o cookie _jwt_screen
    $token = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($authHeader, 'Bearer ')) {
        $token = substr($authHeader, 7);
    }
    if ($token === null && !empty($_COOKIE['_jwt_screen'])) {
        $token = $_COOKIE['_jwt_screen'];
    }

    if ($token === null) {
        apiError('Sin autorización', 401);
    }

    $claims = jwtDecode($token, $_ENV['JWT_SECRET'] ?? '');
    if ($claims === null || ($claims['realm'] ?? '') !== 'screen') {
        apiError('Token inválido', 401);
    }

    $tokenHash = hash('sha256', $token);

    global $db;
    $rs = ncmExecute(
        'SELECT id FROM customer_display WHERE "tokenHash" = ? AND status = 1',
        [$tokenHash],
        true
    );

    if ($rs->EOF) {
        apiError('Pantalla no reconocida', 401);
    }

    $displayId = $rs->fields['id'];

    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip = trim(explode(',', $ip)[0]);

    $db->Execute(
        'UPDATE customer_display SET "lastSeenAt" = now(), "ipLast" = ?::inet WHERE id = ?::uuid',
        [$ip, $displayId]
    );

    apiOk(['ok' => true]);
    exit;
}

// ── Con auth: pair, publish, list, delete ────────────────────────────────────

$ctx       = apiAuthTenant(['panel', 'pos-app']);
$companyId = $ctx['companyId'];

switch (true) {

    // ── POST ?resource=pair — auth pos-app ───────────────────────────────────
    case $method === 'POST' && $resource === 'pair': {
        $pin  = trim($_POST['pin'] ?? '');
        $name = trim($_POST['name'] ?? '');

        if ($pin === '' || $name === '') {
            apiError('pin y name son requeridos', 422);
        }

        $pinKey  = 'screen:pin:' . $pin;
        $pinData = screensRedisCmd('GET', $pinKey);
        if ($pinData === null || $pinData === false || $pinData === '') {
            apiError('PIN inválido o expirado', 404);
        }

        // Eliminar PIN para que no se reutilice
        screensRedisCmd('DEL', $pinKey);

        // Generar UUID para la pantalla
        $row       = ncmExecute('SELECT gen_random_uuid()::text AS id', []);
        $displayId = $row[0]['id'];

        // JWT de larga duración (10 años) para la pantalla
        require_once API_APP_DIR . '/includes/jwt.php';
        $token = jwtEncode([
            'did'   => $displayId,
            'cid'   => $ctx['companyId'],
            'rid'   => $ctx['registerId'],
            'name'  => $name,
            'iat'   => time(),
            'exp'   => time() + (10 * 365 * 24 * 3600),
            'realm' => 'screen',
        ], $_ENV['JWT_SECRET'] ?? '');

        $tokenHash = hash('sha256', $token);

        $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ip = trim(explode(',', $ip)[0]);

        global $db;
        $db->Execute(
            'INSERT INTO customer_display
               (id, "companyId", "registerId", name, "tokenHash", "ipFirst", "ipLast", "lastSeenAt", status)
             VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?::inet, ?::inet, now(), 1)',
            [$displayId, $companyId, $ctx['registerId'], $name, $tokenHash, $ip, $ip]
        );

        // Publicar evento de pairing vía WS para que la pantalla en espera lo capture
        wsPublish('pairing:' . $pin, 'paired', [
            'token'      => $token,
            'registerId' => $ctx['registerId'],
            'companyId'  => $ctx['companyId'],
            'name'       => $name,
        ]);

        apiOk(['ok' => true, 'id' => $displayId]);
        break;
    }

    // ── POST ?resource=publish — auth pos-app ────────────────────────────────
    case $method === 'POST' && $resource === 'publish': {
        $type = $_POST['type'] ?? '';
        $raw  = $_POST['data'] ?? [];
        // El middleware JSON de bootstrap.php ya deserializa el body → $_POST['data']
        // llega como array. Mantenemos compat con clientes legacy que mandan string JSON.
        $data = is_string($raw) ? (json_decode($raw, true) ?? []) : (is_array($raw) ? $raw : []);

        $validTypes = ['cart-update', 'sale-confirmed', 'cart-cleared', 'idle'];
        if (!in_array($type, $validTypes, true)) {
            apiError('tipo inválido', 400);
        }

        wsPublish($ctx['companyId'] . ':checkout:' . $ctx['registerId'], $type, $data);

        apiOk(['ok' => true]);
        break;
    }

    // ── GET — listar pantallas (auth panel) ──────────────────────────────────
    case $method === 'GET': {
        // Tabla `register` se creó sin quotes en el CREATE TABLE → PG fold a
        // lowercase. Por eso `r."registerId"` con quotes FALLA (busca exact
        // case que no existe) y `r."name"` no existe (la columna real es
        // `registerName` → `registername`). Mismo motivo aplica a `register.name`
        // que en realidad es `registerName`. Sin quotes y con el nombre real
        // funciona. Las columnas de customer_display SÍ están en lowercase fold
        // pero como ese CREATE TABLE tampoco usó quotes, también acepta sin quotes.
        $rows = ncmExecute(
            'SELECT cd.id, cd.name, cd.registerId, r.registerName AS "registerName",
                    cd.ipLast::text AS "ipLast", cd.lastSeenAt AS "lastSeenAt",
                    cd.status, cd.createdAt AS "createdAt"
             FROM customer_display cd
             LEFT JOIN register r ON r.registerId = cd.registerId
             WHERE cd.companyId = ?::uuid
             ORDER BY cd.status DESC, cd.createdAt DESC',
            [$companyId]
        );

        apiOk(['screens' => $rows]);
        break;
    }

    // ── DELETE ?id=<uuid> — revocar pantalla (auth panel) ───────────────────
    case $method === 'DELETE': {
        if ($id === null) {
            apiError('id requerido', 422);
        }

        global $db;
        $db->Execute(
            'UPDATE customer_display
             SET status = 0, "revokedAt" = now(), "revokedBy" = ?::uuid
             WHERE id = ?::uuid AND "companyId" = ?::uuid',
            [$ctx['userId'], $id, $companyId]
        );

        if ($db->Affected_Rows() === 0) {
            apiError('Pantalla no encontrada', 404);
        }

        wsPublish('screen:' . $id, 'revoked', []);

        apiOk(['ok' => true]);
        break;
    }

    default:
        apiError('Method not allowed', 405);
}
