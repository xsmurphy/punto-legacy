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
    $host    = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
    $port    = (int)($_ENV['REDIS_PORT'] ?? 6379);
    $prefix  = 'punto:channel:';
    $timeout = 1; // segundo — no bloqueamos el request si Redis no responde

    $payload = json_encode(['event' => $event, 'data' => $data]);
    $redisChannel = $prefix . $channel;

    // Protocolo RESP: PUBLISH <channel> <message>
    $cmd = _redisRespCommand('PUBLISH', $redisChannel, $payload);

    $errno  = 0;
    $errstr = '';
    $sock   = @fsockopen($host, $port, $errno, $errstr, $timeout);

    if (!$sock) {
        error_log("[wsPublish] No se pudo conectar a Redis {$host}:{$port} — {$errstr} ({$errno})");
        return;
    }

    stream_set_timeout($sock, $timeout);
    fwrite($sock, $cmd);
    fgets($sock); // leer respuesta (descartada)
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
