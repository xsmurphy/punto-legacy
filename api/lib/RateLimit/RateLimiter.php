<?php

declare(strict_types=1);

namespace Punto\Api\RateLimit;

use Punto\Api\Cache\RedisClient;

/** El caller excedió su cuota. */
final class RateExceededException extends \RuntimeException
{
}

/** Redis no está disponible y la política del caller es fail-closed. */
final class RateLimiterUnavailableException extends \RuntimeException
{
}

/**
 * RateLimiter — contador de ventana fija con store en Redis.
 *
 * REEMPLAZA a `api/libraries/rateLimiter.php`, que guardaba los contadores en
 * `$_SESSION`. Ese diseño era seguridad DECORATIVA y el comentario de
 * `v1/admin/login.php` ya lo admitía: sin cookie de sesión, cada request
 * estrenaba una sesión vacía, o sea un contador en 0. Un atacante scripteado
 * (que naturalmente no porta cookies) nunca era limitado; el único al que
 * llegaba a frenar era un browser real, que es justo el que no es la amenaza.
 *
 * ALGORITMO — ventana fija: la key incluye el número de ventana
 * (`floor(now / window)`), así que expira sola y no hace falta barrer nada.
 * `INCR` + `EXPIRE` en la primera escritura, ambos dentro de UN script Lua para
 * que sean atómicos: hacerlos en dos roundtrips deja una ventana en la que el
 * proceso muere después del INCR y la key queda SIN TTL, es decir un cliente
 * baneado para siempre.
 *
 * Tradeoff aceptado de la ventana fija: en el borde entre dos ventanas un
 * cliente puede emitir hasta 2× el límite (final de una + principio de la
 * siguiente). Para anti-abuso y anti-fuerza-bruta alcanza; un sliding window
 * log costaría una entrada por request por cliente.
 *
 * POLÍTICA ANTE REDIS CAÍDO — la decide el CALLER, explícitamente, porque no
 * hay una respuesta correcta para los dos casos de uso. Ver `limit()`.
 */
final class RateLimiter
{
    /**
     * INCR + EXPIRE atómicos. Devuelve el contador después de incrementar.
     * KEYS[1] = key, ARGV[1] = TTL en segundos.
     */
    private const LUA_INCR_EXPIRE = <<<'LUA'
        local n = redis.call('INCR', KEYS[1])
        if n == 1 then
          redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return n
        LUA;

    /** Redis caído → el request PASA. Para límites anti-abuso. */
    public const FAIL_OPEN = 'open';

    /** Redis caído → el request se RECHAZA. Para límites que protegen credenciales. */
    public const FAIL_CLOSED = 'closed';

    private readonly string $bucket;

    /**
     * @param string $identity Lo que identifica al sujeto del límite (IP, email+IP, deviceId…).
     * @param string $scope    Namespace del límite; separa contadores de distintos casos de uso.
     */
    public function __construct(string $identity, string $scope = 'global')
    {
        // md5 sobre la identidad: acota el largo de la key y evita que un email
        // con caracteres raros o una IPv6 rompan el keyspace.
        $this->bucket = 'rl:' . $scope . ':' . md5($identity);
    }

    /**
     * Registra un hit y corta si el sujeto excedió `$allowed` en `$windowSeconds`.
     *
     * @param int    $allowed       Hits permitidos por ventana.
     * @param int    $windowSeconds Largo de la ventana.
     * @param string $onFailure     self::FAIL_OPEN | self::FAIL_CLOSED.
     *
     * @throws RateExceededException            Excedió la cuota.
     * @throws RateLimiterUnavailableException  Redis caído y política fail-closed.
     */
    public function limit(int $allowed, int $windowSeconds, string $onFailure): void
    {
        $redis = RedisClient::connect();

        if ($redis === null) {
            if ($onFailure === self::FAIL_CLOSED) {
                throw new RateLimiterUnavailableException('rate limiter no disponible');
            }

            // FAIL_OPEN: ya quedó logeado + reportado a GlitchTip desde
            // RedisClient. No repetimos el ruido por cada request.
            return;
        }

        $key = $this->bucket . ':' . intdiv(time(), max(1, $windowSeconds));

        try {
            $count = $redis->eval(self::LUA_INCR_EXPIRE, [$key, (string) $windowSeconds], 1);
        } catch (\Throwable $e) {
            error_log('[RateLimiter] eval falló: ' . $e->getMessage());
            if (function_exists('\Sentry\captureException')) {
                \Sentry\captureException($e);
            }
            if ($onFailure === self::FAIL_CLOSED) {
                throw new RateLimiterUnavailableException('rate limiter no disponible', 0, $e);
            }

            return;
        }

        // `eval` devuelve false si el script erroró sin lanzar (phpredis no
        // siempre tira excepción). Sin número no podemos afirmar nada.
        if (!is_int($count)) {
            if ($onFailure === self::FAIL_CLOSED) {
                throw new RateLimiterUnavailableException('rate limiter devolvió respuesta inválida');
            }

            return;
        }

        if ($count > $allowed) {
            throw new RateExceededException('rate limit excedido');
        }
    }
}
