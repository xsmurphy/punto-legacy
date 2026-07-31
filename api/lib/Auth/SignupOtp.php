<?php

/**
 * OTP propio del flujo de signup — única fuente de verdad para
 * generar/enviar/validar el código, usada por start.php, verify.php y
 * signup.php.
 *
 * Reemplaza a los scripts legacy `2fapin.php` / `phonevalidator.php`
 * (borrados en la limpieza 2026-06-29), que dejaban el signup muerto en
 * prod porque `start.php` seguía llamándolos por HTTP.
 *
 * Modo controlado por env `SIGNUP_OTP` ('on' | 'off'):
 *   - 'off' (default si la env no está seteada): el owner quiere el
 *     registro funcionando YA — no se genera ni envía código real,
 *     `check()` siempre retorna true. Reactivable con SIGNUP_OTP=on +
 *     Evolution API configurada (EVOLUTION_API_URL/INSTANCE/API_KEY).
 *   - 'on': genera un código de 4 dígitos, lo guarda hasheado (sha256) en
 *     `signup_otp` (mig 106) con expiración de 4 min, y lo valida contra
 *     el hash con rate-limit de 5 intentos.
 *
 * `APP_DEBUG=true` sigue devolviendo/aceptando el código fijo '0000'
 * como antes — ese comportamiento vive en los endpoints (start/verify/
 * signup), no acá, porque corre ANTES de decidir el modo.
 *
 * Tabla `signup_otp`: sin companyId (pre-tenant), phone E.164 SIN '+'
 * como PK (convención storage) — un código activo por número.
 */

declare(strict_types=1);

namespace Punto\Api\Auth;

final class SignupOtp
{
    private const TTL_SECONDS = 240; // 4 minutos
    private const MAX_ATTEMPTS = 5;

    /** @return 'on'|'off' */
    public static function mode(): string
    {
        $mode = strtolower(trim((string) ($_ENV['SIGNUP_OTP'] ?? 'off')));

        return $mode === 'on' ? 'on' : 'off';
    }

    /**
     * Genera un código de 4 dígitos, lo guarda hasheado (UPSERT por phone,
     * resetea attempts) y lo retorna en claro para que el caller lo envíe.
     *
     * @param string $phoneE164 Con o sin '+' — se normaliza acá.
     */
    public static function issue(string $phoneE164): string
    {
        $phone    = ltrim($phoneE164, '+');
        $code     = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', $code);

        // Expiración calculada EN SQL (now() del server + interval) — un
        // date() de PHP acá sería naive del container UTC y la columna
        // timestamptz lo interpretaría tenant-local: TTL de 3h4min en vez
        // de 4min (mismo bug de TZ que el data-fix de la mig 104).
        ncmExecute(
            "INSERT INTO signup_otp (phone, codehash, expires_at, attempts, created_at)
             VALUES (?, ?, now() + make_interval(secs => ?), 0, now())
             ON CONFLICT (phone) DO UPDATE SET
               codehash   = EXCLUDED.codehash,
               expires_at = EXCLUDED.expires_at,
               attempts   = 0,
               created_at = now()",
            [$phone, $codeHash, self::TTL_SECONDS]
        );

        return $code;
    }

    /**
     * Valida el código contra el hash guardado. Modo 'off' → siempre true
     * (sin tocar la tabla). Incrementa `attempts` en cada chequeo en modo
     * 'on'; a partir de 5 intentos el código queda invalidado aunque sea
     * el correcto (rate limit anti brute-force).
     */
    public static function check(string $phoneE164, string $code): bool
    {
        if (self::mode() === 'off') {
            return true;
        }

        $phone = ltrim($phoneE164, '+');
        $row   = ncmExecute('SELECT * FROM signup_otp WHERE phone = ?', [$phone]);

        if (!is_array($row)) {
            return false; // sin código emitido (o expiró y fue limpiado)
        }

        $attempts = (int) ($row['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        ncmExecute('UPDATE signup_otp SET attempts = attempts + 1 WHERE phone = ?', [$phone]);

        // Expiración también comparada en SQL — evita strtotime() contra un
        // literal cuya TZ depende de cómo la sesión de PG serialice.
        $fresh = ncmExecute('SELECT 1 AS ok FROM signup_otp WHERE phone = ? AND expires_at > now()', [$phone]);
        if (!is_array($fresh)) {
            return false;
        }

        return hash_equals((string) ($row['codehash'] ?? ''), hash('sha256', $code));
    }
}
