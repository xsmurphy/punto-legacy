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
 * @param array  $payload    Datos de la acción a confirmar.
 * @param string $companyId  ID del tenant (para validación en consume).
 * @param string $userId     ID del usuario que generó la acción.
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
 * Consume (GET + DEL atómico) un token de confirmación.
 *
 * Valida que el token pertenezca al companyId del caller.
 *
 * @return array|null Payload con keys [action, payload, companyId, userId], o null si inválido/expirado.
 */
function aiConfirmStoreConsume(string $token, string $companyId): ?array
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
    aiConfirmStoreCmd('DEL', $key);
    return $data;
}
