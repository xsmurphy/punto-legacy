<?php
/**
 * Publicador de eventos WebSocket via Redis Pub/Sub.
 *
 * Reemplaza las llamadas a Pusher desde PHP.
 * El servidor Node.js (ws-server/) escucha los canales y reenvía a los clientes.
 *
 * Uso:
 *   wsPublish('outlet123-KDS', 'order', ['orderId' => 42, 'items' => [...]]);
 *   wsPublish('outlet123-register', 'sale', ['total' => 15000]);
 *
 * No lanza excepciones — falla silenciosamente para no interrumpir el flujo de negocio.
 * Los errores se registran en el error_log del servidor.
 */

/**
 * Publica un evento en un canal WebSocket.
 *
 * @param string $channel  Nombre del canal (mismo esquema que Pusher: "{outletId}-KDS", etc.)
 * @param string $event    Nombre del evento ("order", "sale", "update", etc.)
 * @param array  $data     Payload del evento
 */
function wsPublish(string $channel, string $event, array $data = []): void
{
    // Soporte para REDIS_URL (Coolify style): redis://default:<pass>@host:6379/0
    // parse_url no decodifica el componente pass; urldecode es necesario para passwords URL-encoded.
    $user = null;
    $pass = null;
    $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
    $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
    if (!empty($_ENV['REDIS_URL'])) {
        $ru   = parse_url($_ENV['REDIS_URL']);
        $host = $ru['host'] ?? '127.0.0.1';
        $port = (int)($ru['port'] ?? 6379);
        $user = isset($ru['user']) ? $ru['user'] : null;
        $pass = isset($ru['pass']) ? urldecode($ru['pass']) : null;
    }
    $prefix  = 'punto:channel:';
    $timeout = 1; // segundo — no bloqueamos el request si Redis no responde

    $payload = json_encode(['event' => $event, 'data' => $data]);
    $redisChannel = $prefix . $channel;

    // Construir pipeline: AUTH (si aplica) + PUBLISH en un solo fwrite
    $cmds = '';
    if ($pass !== null) {
        if ($user !== null && $user !== '' && $user !== 'default') {
            // Redis 6+ ACL: AUTH <user> <pass>
            $cmds .= _redisRespCommand('AUTH', $user, $pass);
        } else {
            // Legacy / usuario default: AUTH <pass>
            $cmds .= _redisRespCommand('AUTH', $pass);
        }
    }
    $cmds .= _redisRespCommand('PUBLISH', $redisChannel, $payload);

    $errno  = 0;
    $errstr = '';
    $sock   = @fsockopen($host, $port, $errno, $errstr, $timeout);

    if (!$sock) {
        error_log("[wsPublish] No se pudo conectar a Redis {$host}:{$port} — {$errstr} ({$errno})");
        return;
    }

    stream_set_timeout($sock, $timeout);
    fwrite($sock, $cmds);

    if ($pass !== null) {
        $authResp = fgets($sock);
        if ($authResp === false || strpos($authResp, '+OK') !== 0) {
            error_log('[wsPublish] AUTH falló: ' . trim((string)$authResp));
            fclose($sock);
            return;
        }
    }
    fgets($sock); // leer respuesta del PUBLISH (descartada)
    fclose($sock);
}

/**
 * Construye un comando Redis en formato RESP (Redis Serialization Protocol).
 * Solo para comandos simples de tipo array — suficiente para PUBLISH.
 */
function _redisRespCommand(string ...$parts): string
{
    $out = '*' . count($parts) . "\r\n";
    foreach ($parts as $part) {
        $out .= '$' . strlen($part) . "\r\n" . $part . "\r\n";
    }
    return $out;
}
