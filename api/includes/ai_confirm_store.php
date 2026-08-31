<?php
/**
 * ai_confirm_store — Token de confirmación para acciones del agente IA.
 *
 * Patrón Redis idéntico a screens.php: fsockopen + RESP nativo.
 * Key: ai:confirm:<token>, TTL 300 segundos.
 */

function aiConfirmStoreRedisConfig(): array
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

function aiConfirmStoreRespCmd(string ...$parts): string
{
    $out = '*' . count($parts) . "\r\n";
    foreach ($parts as $part) {
        $out .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
    }
    return $out;
}

function aiConfirmStoreReadReply($sock): mixed
{
    $line = fgets($sock);
    if ($line === false) return null;
    $type    = $line[0];
    $payload = substr($line, 1, -2);
    switch ($type) {
        case '+': return $payload;
        case '-': return null;
        case ':': return (int) $payload;
        case '$':
            $len = (int) $payload;
            if ($len < 0) return null;
            $data = '';
            while (strlen($data) < $len) {
                $chunk = fread($sock, $len - strlen($data));
                if ($chunk === false || $chunk === '') break;
                $data .= $chunk;
            }
            fread($sock, 2);
            return $data;
        default: return null;
    }
}

function aiConfirmStoreCmd(string ...$args): mixed
{
    [$host, $port, $pass] = aiConfirmStoreRedisConfig();
    $errno = 0; $errstr = '';
    $sock  = @fsockopen($host, $port, $errno, $errstr, 2);
    if (!$sock) {
        error_log("[ai_confirm] No se pudo conectar a Redis {$host}:{$port} — {$errstr}");
        return null;
    }
    stream_set_timeout($sock, 2);
    if ($pass !== '') {
        fwrite($sock, aiConfirmStoreRespCmd('AUTH', $pass));
        $auth = aiConfirmStoreReadReply($sock);
        if ($auth !== 'OK') {
            error_log('[ai_confirm] AUTH falló contra Redis');
            fclose($sock);
            return null;
        }
    }
    fwrite($sock, aiConfirmStoreRespCmd(...$args));
    $reply = aiConfirmStoreReadReply($sock);
    fclose($sock);
    return $reply;
}

/**
 * Crea un token de confirmación y lo persiste en Redis.
 *
 * @param array  $payload    Datos del LOTE a confirmar. Shape: ['actions' =>
 *                            [payload1, payload2, ...]], donde cada payloadN
 *                            trae su propia key 'action'. Este store no
 *                            interpreta la forma — solo la serializa.
 * @param string $companyId  ID del tenant (para validación en consume).
 * @param string $userId     ID de la PERSONA que pidió la acción — el operador
 *                            del PIN en la caja, el usuario logueado en el
 *                            panel. `consume` exige que sea el mismo.
 * @return string|null Token de 32 hex chars, o null si Redis no responde.
 */
function aiConfirmStoreCreate(array $payload, string $companyId, string $userId): ?string
{
    $token = bin2hex(random_bytes(16)); // 32 hex chars
    $data  = json_encode([
        'payload'   => $payload,
        'companyId' => $companyId,
        'userId'    => $userId,
        'createdAt' => time(),
    ], JSON_UNESCAPED_UNICODE);

    $key    = 'ai:confirm:' . $token;
    $result = aiConfirmStoreCmd('SET', $key, $data, 'EX', '300');
    if ($result !== 'OK') {
        return null;
    }
    return $token;
}

/**
 * Consume (GET + DEL) un token de confirmación.
 *
 * Valida DOS cosas: que el token sea del tenant del caller y que lo consuma la
 * MISMA persona que lo pidió.
 *
 * ── Por qué el dueño también se valida ─────────────────────────────────────
 *
 * Hasta 2026-08-31 el token solo se ataba al tenant: el `userId` se guardaba y
 * no se miraba nunca. En el panel eso era una laxitud teórica (hay que robar un
 * token de 5 minutos de vida de otra sesión de la misma empresa). En la CAJA
 * deja de ser teórica: una tablet la desbloquean tres personas por turno, el
 * token viaja por el chat de esa tablet, y sin este check el lote que registró
 * el encargado —con SUS permisos— lo podría ejecutar quien tipee su PIN después.
 * La confirmación dejaría de significar "esta persona aprobó esto".
 *
 * Mismatch devuelve null SIN borrar la key: el token sigue siendo de su dueño
 * legítimo y no se le quema porque otro lo haya intentado.
 *
 * @param string $actorUserId la persona que consume — operador del PIN en la
 *                            caja, usuario logueado en el panel.
 * @return array|null Payload con keys [payload, companyId, userId, createdAt], donde
 *                     payload = ['actions' => [...]] (el lote completo), o null si
 *                     inválido/expirado/de otra persona.
 */
function aiConfirmStoreConsume(string $token, string $companyId, string $actorUserId): ?array
{
    $key  = 'ai:confirm:' . $token;
    $raw  = aiConfirmStoreCmd('GET', $key);
    if ($raw === null || $raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    if (($data['companyId'] ?? '') !== $companyId) {
        return null;
    }
    if ($actorUserId === '' || (string) ($data['userId'] ?? '') !== $actorUserId) {
        error_log('[ai_confirm] token consumido por otra persona que la que lo registró — rechazado');
        return null;
    }
    aiConfirmStoreCmd('DEL', $key);
    return $data;
}
