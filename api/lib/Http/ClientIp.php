<?php

declare(strict_types=1);

namespace Punto\Api\Http;

/**
 * ClientIp — resolutor canónico de la IP del cliente. ÚNICA fuente de verdad.
 *
 * POR QUÉ EXISTE
 * --------------
 * En prod la API corre detrás de Traefik (`coolify-proxy`) en la red Docker
 * `coolify`. Verificado en el container de la API (2026-08-22, logs de `php -S`):
 * TODO request externo llega con `REMOTE_ADDR = 172.18.0.2` — la IP de Traefik —
 * y los jobs del crond interno llegan con `127.0.0.1`.
 *
 * Es decir: `$_SERVER['REMOTE_ADDR']` NO identifica al cliente, identifica al
 * proxy. Cualquier cosa keyed por REMOTE_ADDR (rate limit, throttle, auditoría)
 * colapsa a TODOS los clientes en un solo balde. El rate limiter global de
 * `head.php` es exactamente ese caso: con store real y key REMOTE_ADDR, los
 * 80 req/min serían 80 req/min para la plataforma ENTERA.
 *
 * MODELO DE CONFIANZA
 * -------------------
 * `X-Forwarded-For` lo puede escribir cualquiera, así que sólo se lee cuando el
 * peer directo (REMOTE_ADDR) es un proxy en el que confiamos — loopback o rango
 * privado, que es donde vive nuestra red Docker. Desde internet nadie puede
 * hacerse pasar por 172.18.0.2: para eso tendría que estar dentro de la red.
 *
 * Dentro del XFF se recorre de DERECHA A IZQUIERDA y se toma la primera entrada
 * que NO sea privada. Esa es la que agregó nuestro propio proxy de borde. Tomar
 * la izquierda (el patrón ingenuo, y lo que hacía el `getUserIpAddr()` legacy)
 * es spoofeable: el atacante manda `X-Forwarded-For: 1.2.3.4`, Traefik le hace
 * append de la IP real, el header queda `1.2.3.4, <ip-real>`, y leyendo la
 * izquierda el atacante elige su propia key de rate limit y rota infinito.
 *
 * `HTTP_CLIENT_IP` NO se lee nunca: ningún proxy de nuestro stack lo escribe,
 * así que sólo puede venir del cliente — es 100% spoofeable. El
 * `getUserIpAddr()` legacy lo leía PRIMERO; por eso se eliminó.
 */
final class ClientIp
{
    /**
     * Rangos desde los que aceptamos `X-Forwarded-For`, y que descartamos como
     * candidatos a "IP real del cliente". Loopback + RFC1918 + link-local +
     * CGNAT + equivalentes IPv6. Cubre la red Docker sin hardcodear la subred
     * que Coolify le asigne a cada deploy.
     */
    private const TRUSTED_CIDRS = [
        '127.0.0.0/8',      // loopback (crond interno del container)
        '10.0.0.0/8',       // RFC1918
        '172.16.0.0/12',    // RFC1918 — acá viven las redes Docker (172.18/172.19)
        '192.168.0.0/16',   // RFC1918
        '169.254.0.0/16',   // link-local
        '100.64.0.0/10',    // CGNAT (RFC6598)
        '::1/128',          // loopback v6
        'fc00::/7',         // unique-local v6
        'fe80::/10',        // link-local v6
    ];

    /** Cache por request: se llama desde head.php y de nuevo desde auditoría. */
    private static ?string $cached = null;

    /**
     * IP del cliente final, o '' si no se puede determinar (no debería pasar en
     * HTTP real; sí en CLI, donde REMOTE_ADDR no existe).
     */
    public static function resolve(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        return self::$cached = self::compute();
    }

    /** Resetea el cache. Sólo para tests — en un request real la IP no cambia. */
    public static function resetCache(): void
    {
        self::$cached = null;
    }

    private static function compute(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        // Peer directo NO confiable (o ausente) → es el cliente. No leer headers.
        if ($remote === '' || !self::isTrusted($remote)) {
            return $remote;
        }

        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '') {
            // Proxy sin XFF: lo mejor que tenemos es el peer. Pasa con el crond
            // interno (127.0.0.1), que es tráfico nuestro y no queremos mezclar
            // con el resto — devolver el loopback lo deja en su propio balde.
            return $remote;
        }

        // Derecha → izquierda: la primera pública es la que puso nuestro borde.
        $hops = explode(',', $xff);
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $ip = self::normalize($hops[$i]);
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!self::isTrusted($ip)) {
                return $ip;
            }
        }

        // Toda la cadena es privada (ej. healthcheck interno vía proxy).
        return $remote;
    }

    /**
     * Limpia una entrada de XFF: saca el puerto de `1.2.3.4:5678` y los
     * corchetes de `[::1]:443`. IPv6 sin corchetes se deja tal cual (tiene ':'
     * pero no es un puerto).
     */
    private static function normalize(string $raw): string
    {
        $ip = trim($raw);
        if ($ip === '') {
            return '';
        }
        // [v6]:puerto  o  [v6]
        if ($ip[0] === '[') {
            $close = strpos($ip, ']');

            return $close === false ? '' : substr($ip, 1, $close - 1);
        }
        // v4:puerto — un solo ':' significa que lo de atrás es puerto.
        if (substr_count($ip, ':') === 1) {
            return substr($ip, 0, (int) strpos($ip, ':'));
        }

        return $ip;
    }

    /** ¿La IP cae en alguno de los rangos de TRUSTED_CIDRS? */
    private static function isTrusted(string $ip): bool
    {
        foreach (self::TRUSTED_CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Match de IP contra CIDR, v4 y v6, comparando a nivel de bits. */
    private static function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bitsRaw] = explode('/', $cidr, 2);
        $bits = (int) $bitsRaw;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }
        // Distinta familia (v4 vs v6) → no puede matchear.
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $rest  = $bits % 8;

        if ($whole > 0 && substr($ipBin, 0, $whole) !== substr($subnetBin, 0, $whole)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }
        if (!isset($ipBin[$whole], $subnetBin[$whole])) {
            return false;
        }
        $mask = ~((1 << (8 - $rest)) - 1) & 0xFF;

        return (ord($ipBin[$whole]) & $mask) === (ord($subnetBin[$whole]) & $mask);
    }
}
