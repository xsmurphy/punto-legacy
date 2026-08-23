<?php

declare(strict_types=1);

/**
 * Test de integración (Redis real) del rate limiter con store en Redis
 * (`Punto\Api\RateLimit\RateLimiter`) y del resolutor de IP de cliente
 * (`Punto\Api\Http\ClientIp`).
 *
 * POR QUÉ ESTE TEST EXISTE
 * ------------------------
 * El limiter viejo (`api/libraries/rateLimiter.php`) guardaba los contadores en
 * `$_SESSION`. Sin cookie, cada request estrenaba una sesión vacía → contador en
 * 0 → el atacante scripteado NUNCA era limitado. El caso (d) de acá es
 * exactamente esa regresión: dos "clientes" sin nada compartido salvo la IP
 * tienen que sumar al MISMO contador. Con el diseño viejo ese caso fallaba.
 *
 * Casos:
 *   (a) N requests bajo el límite pasan.
 *   (b) el que excede recibe 429 (RateExceededException).
 *   (c) pasada la ventana, vuelve a permitir.
 *   (d) dos "sesiones" distintas (sin cookie compartida) comparten el contador
 *       por IP — el punto entero del cambio.
 *   (e) con Redis caído: FAIL_OPEN deja pasar, FAIL_CLOSED corta
 *       (RateLimiterUnavailableException). La política documentada de cada
 *       caso de uso: head.php = FAIL_OPEN, /v1/admin/login = FAIL_CLOSED.
 *   (f) ClientIp: detrás de un proxy confiable gana la entrada DERECHA de
 *       X-Forwarded-For (la izquierda es spoofeable por el cliente), y un peer
 *       NO confiable ignora el header por completo.
 *
 * Uso (necesita un Redis alcanzable — ver `run_rate_limiter_test.sh` para
 * levantar uno descartable en Docker):
 *   REDIS_URL=redis://127.0.0.1:6379/0 php api/tests/rate_limiter_test.php
 *
 * Exit code 0 si todos los casos pasan, 1 si alguno falla.
 */

require_once dirname(__DIR__) . '/lib/Cache/RedisClient.php';
require_once dirname(__DIR__) . '/lib/RateLimit/RateLimiter.php';
require_once dirname(__DIR__) . '/lib/Http/ClientIp.php';

use Punto\Api\Cache\RedisClient;
use Punto\Api\Http\ClientIp;
use Punto\Api\RateLimit\RateExceededException;
use Punto\Api\RateLimit\RateLimiter;
use Punto\Api\RateLimit\RateLimiterUnavailableException;

$failures = 0;
$passes   = 0;

function ok(string $msg): void
{
    global $passes;
    $passes++;
    echo "  OK   {$msg}\n";
}

function bad(string $msg): void
{
    global $failures;
    $failures++;
    echo "  FAIL {$msg}\n";
}

function check(bool $cond, string $msg): void
{
    $cond ? ok($msg) : bad($msg);
}

/** ¿`limit()` dejó pasar este hit? */
function allows(RateLimiter $rl, int $allowed, int $window, string $policy = RateLimiter::FAIL_OPEN): bool
{
    try {
        $rl->limit($allowed, $window, $policy);

        return true;
    } catch (RateExceededException) {
        return false;
    }
}

// Identidad única por corrida: los tests no se pisan entre sí ni con corridas previas.
$run = bin2hex(random_bytes(6));

echo "\n=== rate_limiter_test — store en Redis (run {$run}) ===\n";

// ── Sanity: Redis tiene que estar arriba, si no el test no prueba nada ──────
if (RedisClient::connect() === null) {
    fwrite(STDERR, "\nERROR: no hay Redis alcanzable. Seteá REDIS_URL (ej. redis://127.0.0.1:6379/0)\n");
    fwrite(STDERR, "       o usá api/tests/run_rate_limiter_test.sh, que levanta uno descartable.\n\n");
    exit(1);
}

// ── (a) N requests bajo el límite pasan ────────────────────────────────────
echo "\n(a) requests bajo el límite pasan\n";
$limit  = 5;
$window = 60;
$rlA    = new RateLimiter("a:{$run}", 'test');
$allOk  = true;
for ($i = 1; $i <= $limit; $i++) {
    if (!allows($rlA, $limit, $window)) {
        $allOk = false;
        bad("request #{$i} de {$limit} fue rechazado y no debía");
        break;
    }
}
check($allOk, "los {$limit} requests dentro de la cuota pasaron");

// ── (b) el que excede recibe 429 ───────────────────────────────────────────
echo "\n(b) el request que excede es rechazado\n";
check(!allows($rlA, $limit, $window), 'request #' . ($limit + 1) . ' rechazado (RateExceededException)');
// Y sigue rechazando: no es un rebote de una sola vez.
check(!allows($rlA, $limit, $window), 'request #' . ($limit + 2) . ' también rechazado');

// ── (c) pasada la ventana, vuelve a permitir ───────────────────────────────
// Ventana de 1s para no dormir 60s. La key incluye intdiv(time(), window), así
// que cruzar el borde de segundo estrena contador.
echo "\n(c) pasada la ventana el contador se reinicia\n";
$rlC = new RateLimiter("c:{$run}", 'test');
check(allows($rlC, 1, 1), 'primer request de la ventana pasa');
check(!allows($rlC, 1, 1), 'segundo request de la MISMA ventana es rechazado');

// Esperar a cruzar el borde de la ventana (hasta 3s de margen).
$startWindow = intdiv(time(), 1);
$deadline    = microtime(true) + 3.0;
while (intdiv(time(), 1) === $startWindow && microtime(true) < $deadline) {
    usleep(50_000);
}
check(allows($rlC, 1, 1), 'tras expirar la ventana vuelve a permitir');

// Y el TTL existe: sin EXPIRE la key quedaría para siempre (cliente baneado eterno).
$redis   = RedisClient::connect();
$probeRl = new RateLimiter("ttl:{$run}", 'test');
$probeRl->limit(10, 120, RateLimiter::FAIL_OPEN);
$ttlKey  = 'rl:test:' . md5("ttl:{$run}") . ':' . intdiv(time(), 120);
$ttl     = $redis->ttl($ttlKey);
check(is_int($ttl) && $ttl > 0 && $ttl <= 120, "la key tiene TTL ({$ttl}s) — INCR+EXPIRE atómicos");

// ── (d) dos "sesiones" distintas comparten el contador por IP ──────────────
// ESTE es el caso que el limiter viejo NO pasaba. Cada objeto RateLimiter es un
// request independiente, sin cookie ni estado compartido: lo único en común es
// la identidad (la IP). Con store en $_SESSION cada uno arrancaba en 0.
echo "\n(d) dos clientes sin cookie compartida suman al MISMO contador por IP\n";
$ip      = "203.0.113.{$run}";
$limitD  = 3;
$hits    = 0;
$blocked = 0;
for ($i = 0; $i < 6; $i++) {
    // Instancia NUEVA cada vuelta = request nuevo, "sesión" nueva.
    $rl = new RateLimiter($ip, 'test');
    if (allows($rl, $limitD, 60)) {
        $hits++;
    } else {
        $blocked++;
    }
}
check($hits === $limitD, "pasaron exactamente {$limitD} (pasaron {$hits})");
check($blocked === 3, "se bloquearon los 3 excedentes (bloqueados {$blocked})");

// Y una IP DISTINTA no arrastra el contador de la anterior.
$rlOther = new RateLimiter("198.51.100.{$run}", 'test');
check(allows($rlOther, $limitD, 60), 'otra IP arranca con su propio contador');

// ── (e) Redis caído: FAIL_OPEN pasa, FAIL_CLOSED corta ─────────────────────
echo "\n(e) comportamiento con Redis caído\n";
$savedUrl = $_ENV['REDIS_URL'] ?? null;
RedisClient::reset();
// Puerto cerrado → connect() falla → RedisClient::connect() devuelve null.
$_ENV['REDIS_URL'] = 'redis://127.0.0.1:1/0';
putenv('REDIS_URL=redis://127.0.0.1:1/0');

$rlDown = new RateLimiter("down:{$run}", 'test');

// FAIL_OPEN (head.php): el request pasa. Una caída de cache no puede tumbar la API.
$openPassed = true;
try {
    $rlDown->limit(1, 60, RateLimiter::FAIL_OPEN);
    $rlDown->limit(1, 60, RateLimiter::FAIL_OPEN); // ni siquiera el que excedería
} catch (\Throwable $e) {
    $openPassed = false;
}
check($openPassed, 'FAIL_OPEN (head.php): con Redis caído el request PASA');

// FAIL_CLOSED (/v1/admin/login): corta. Es lo único delante de un bcrypt sin autenticar.
$closedThrew = false;
try {
    $rlDown->limit(10, 60, RateLimiter::FAIL_CLOSED);
} catch (RateLimiterUnavailableException) {
    $closedThrew = true;
}
check($closedThrew, 'FAIL_CLOSED (/v1/admin/login): con Redis caído el request se RECHAZA (503)');

// Restaurar Redis real.
RedisClient::reset();
if ($savedUrl !== null) {
    $_ENV['REDIS_URL'] = $savedUrl;
    putenv('REDIS_URL=' . $savedUrl);
} else {
    unset($_ENV['REDIS_URL']);
    putenv('REDIS_URL');
}
check(RedisClient::connect() !== null, 'Redis vuelve a conectar tras restaurar la config');

// ── (f) ClientIp: proxy confiable, entrada derecha de XFF ──────────────────
echo "\n(f) ClientIp — resolución de la IP real detrás del proxy\n";

/** Simula un request y resuelve la IP. */
function resolveWith(?string $remote, ?string $xff): string
{
    $_SERVER['REMOTE_ADDR'] = $remote;
    if ($xff === null) {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    } else {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    }
    ClientIp::resetCache();

    return ClientIp::resolve();
}

// Caso real de prod: Traefik en 172.18.0.2 hace append de la IP del cliente.
check(
    resolveWith('172.18.0.2', '198.51.100.7') === '198.51.100.7',
    'detrás de Traefik (172.18.0.2) devuelve la IP del cliente, no la del proxy'
);

// Spoofing: el cliente manda su propio XFF; Traefik le hace append de la real.
// Leer la IZQUIERDA daría 1.2.3.4 (elegida por el atacante) → tiene que ganar la derecha.
check(
    resolveWith('172.18.0.2', '1.2.3.4, 198.51.100.7') === '198.51.100.7',
    'XFF spoofeado: gana la entrada DERECHA (la que puso nuestro proxy)'
);

// Peer NO confiable (conexión directa): el header se ignora entero.
check(
    resolveWith('198.51.100.9', '1.2.3.4') === '198.51.100.9',
    'peer no confiable: se ignora X-Forwarded-For'
);

// Sin XFF detrás del proxy → el peer es lo mejor que hay (crond interno).
check(resolveWith('127.0.0.1', null) === '127.0.0.1', 'crond interno (127.0.0.1) sin XFF queda en su propio balde');

// Puerto en la entrada de XFF.
check(resolveWith('172.18.0.2', '198.51.100.7:44321') === '198.51.100.7', 'se descarta el puerto de la entrada de XFF');

// Cadena toda privada → cae al peer.
check(resolveWith('10.0.0.5', '10.0.0.9, 172.18.0.2') === '10.0.0.5', 'cadena enteramente privada cae al REMOTE_ADDR');

// Y la clave del asunto: dos clientes DISTINTOS detrás del mismo proxy no
// comparten contador (que es lo que pasaría usando REMOTE_ADDR pelado).
$ipX = resolveWith('172.18.0.2', '198.51.100.20');
$ipY = resolveWith('172.18.0.2', '198.51.100.21');
check($ipX !== $ipY, 'dos clientes detrás del MISMO proxy resuelven a IPs distintas');

// ── Limpieza de las keys de esta corrida ───────────────────────────────────
$redis = RedisClient::connect();
if ($redis !== null) {
    $it = null;
    // Barrido acotado al prefijo de test; no toca nada más del keyspace.
    while (($keys = $redis->scan($it, 'rl:test:*', 500)) !== false) {
        if ($keys) {
            $redis->del($keys);
        }
        if ($it === 0 || $it === null) {
            break;
        }
    }
}

echo "\n=== resultado: {$passes} OK, {$failures} FAIL ===\n\n";
exit($failures === 0 ? 0 : 1);
