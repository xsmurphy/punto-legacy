<?php

declare(strict_types=1);

namespace Punto\Api\Cache;

/**
 * RedisClient — conector Redis canónico de la API (extensión `phpredis`).
 *
 * Una sola conexión por request, cacheada. El FALLO también se cachea: si Redis
 * no responde, el primer intento paga el timeout y los siguientes devuelven null
 * al instante. Sin eso, un Redis caído multiplicaría el timeout por cada llamada
 * del request y convertiría una degradación en un cuelgue.
 *
 * CONFIG — se lee `REDIS_URL` (`redis://[user[:pass]@]host[:port][/db]`), que es
 * lo que ya inyecta Coolify. NO se agregan env vars nuevas. Se acepta el
 * fallback REDIS_HOST/PORT/PASSWORD por compatibilidad con el patrón que ya usa
 * `includes/ai_confirm_store.php`.
 *
 * DEUDA CONOCIDA (fuera del alcance de este cambio): `includes/ai_confirm_store.php`,
 * `includes/screens.php` y `includes/ws_publish.php` hablan RESP a mano sobre
 * `fsockopen`, cada uno con su propio parser y su propio parseo de REDIS_URL —
 * tres copias del mismo código. Esta clase es el reemplazo previsto para las
 * tres; no se migraron acá porque hay sesiones en paralelo sobre `ai/*`.
 */
final class RedisClient
{
    /** Timeouts cortos: Redis es local a la red Docker; si tarda, está caído. */
    private const CONNECT_TIMEOUT = 0.5;
    private const READ_TIMEOUT    = 0.5;

    private static ?\Redis $conn = null;

    /** true una vez que un intento falló — evita reintentar dentro del request. */
    private static bool $failed = false;

    /**
     * Conexión lista para usar, o null si Redis no está disponible.
     * El caller DECIDE qué hacer con el null (fail-open vs fail-closed); esta
     * clase nunca toma esa decisión por él.
     */
    public static function connect(): ?\Redis
    {
        if (self::$conn !== null) {
            return self::$conn;
        }
        if (self::$failed) {
            return null;
        }

        if (!extension_loaded('redis')) {
            self::markFailed('extensión phpredis no cargada');

            return null;
        }

        [$host, $port, $pass, $db] = self::config();
        if ($host === '') {
            self::markFailed('REDIS_URL / REDIS_HOST sin configurar');

            return null;
        }

        try {
            $redis = new \Redis();
            if (!$redis->connect($host, $port, self::CONNECT_TIMEOUT)) {
                self::markFailed("connect() falló contra {$host}:{$port}");

                return null;
            }
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, self::READ_TIMEOUT);
            if ($pass !== '' && !$redis->auth($pass)) {
                self::markFailed('AUTH rechazado');

                return null;
            }
            if ($db > 0) {
                $redis->select($db);
            }

            return self::$conn = $redis;
        } catch (\Throwable $e) {
            self::markFailed($e->getMessage());

            return null;
        }
    }

    /** Cierra y olvida la conexión. Sólo para tests. */
    public static function reset(): void
    {
        if (self::$conn !== null) {
            try {
                self::$conn->close();
            } catch (\Throwable) {
                // Da igual: la estamos tirando.
            }
        }
        self::$conn   = null;
        self::$failed = false;
    }

    /**
     * Parseo de REDIS_URL → [host, port, pass, db].
     * Formato Coolify: `redis://default:PASS@host:6379/0`.
     */
    private static function config(): array
    {
        $url = (string) ($_ENV['REDIS_URL'] ?? getenv('REDIS_URL') ?: '');

        if ($url !== '') {
            $u    = parse_url($url);
            $host = $u['host'] ?? '';
            $port = (int) ($u['port'] ?? 6379);
            $pass = isset($u['pass']) ? urldecode((string) $u['pass']) : '';
            // `/0` → db 0. Sin path, db 0.
            $db = 0;
            if (isset($u['path'])) {
                $trimmed = trim((string) $u['path'], '/');
                if ($trimmed !== '' && ctype_digit($trimmed)) {
                    $db = (int) $trimmed;
                }
            }

            return [$host, $port, $pass, $db];
        }

        return [
            (string) ($_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: ''),
            (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379),
            (string) ($_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: ''),
            (int) ($_ENV['REDIS_DB'] ?? getenv('REDIS_DB') ?: 0),
        ];
    }

    /** Marca el fallo, lo logea una vez y lo reporta a GlitchTip. */
    private static function markFailed(string $why): void
    {
        self::$failed = true;
        error_log("[RedisClient] Redis no disponible: {$why}");
        if (function_exists('\Sentry\captureMessage')) {
            \Sentry\captureMessage("Redis no disponible: {$why}");
        }
    }
}
