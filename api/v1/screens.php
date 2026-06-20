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

function screensGetRedis(): ?Redis
{
    try {
        $r = new Redis();
        $r->connect(
            $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            intval($_ENV['REDIS_PORT'] ?? 6379)
        );
        $pass = $_ENV['REDIS_PASSWORD'] ?? '';
        if ($pass !== '') {
            $r->auth($pass);
        }
        return $r;
    } catch (\Throwable $e) {
        return null;
    }
}

// ── Routing sin auth ─────────────────────────────────────────────────────────

$resource = $_GET['resource'] ?? null;
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$id       = $_GET['id'] ?? null;

// ── POST ?resource=request — sin auth ────────────────────────────────────────

if ($method === 'POST' && $resource === 'request') {
    $ip  = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    $ip  = trim(explode(',', $ip)[0]);

    $redis = screensGetRedis();
    if ($redis === null) {
        apiError('Servicio no disponible', 503);
    }

    // Rate-limit: max 10 requests / 60s por IP
    $rateKey = 'screen:rate:' . $ip;
    $count   = (int)$redis->incr($rateKey);
    if ($count === 1) {
        $redis->expire($rateKey, 60);
    }
    if ($count > 10) {
        apiError('Demasiados intentos. Espera un momento.', 429);
    }

    // Generar PIN de 6 dígitos
    $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // Guardar en Redis 5 minutos
    $redis->setex(
        'screen:pin:' . $pin,
        300,
        json_encode(['ip' => $ip, 'requestedAt' => time()])
    );

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

        $redis = screensGetRedis();
        if ($redis === null) {
            apiError('Servicio no disponible', 503);
        }

        $pinKey  = 'screen:pin:' . $pin;
        $pinData = $redis->get($pinKey);
        if ($pinData === false || $pinData === null) {
            apiError('PIN inválido o expirado', 404);
        }

        // Eliminar PIN para que no se reutilice
        $redis->del($pinKey);

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
        $data = isset($_POST['data']) ? json_decode($_POST['data'], true) : [];

        $validTypes = ['cart-update', 'sale-confirmed', 'cart-cleared', 'idle'];
        if (!in_array($type, $validTypes, true)) {
            apiError('tipo inválido', 400);
        }

        wsPublish($ctx['companyId'] . ':checkout:' . $ctx['registerId'], $type, $data ?? []);

        apiOk(['ok' => true]);
        break;
    }

    // ── GET — listar pantallas (auth panel) ──────────────────────────────────
    case $method === 'GET': {
        $rows = ncmExecute(
            'SELECT cd.id, cd.name, cd."registerId", r.name AS "registerName",
                    cd."ipLast"::text, cd."lastSeenAt", cd.status, cd."createdAt"
             FROM customer_display cd
             LEFT JOIN register r ON r."registerId" = cd."registerId"
             WHERE cd."companyId" = ?::uuid
             ORDER BY cd.status DESC, cd."createdAt" DESC',
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
