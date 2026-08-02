<?php

/**
 * PlatformConfig.php — lectura/escritura de `platform_config` (mig 108), la
 * tabla key→jsonb de configuración global de la plataforma (integraciones de
 * negocio, flags). Ver context/34-admin-saas-plan.md F6 §3.
 *
 * Regla de precedencia (cerrada, ver plan): si existe la key en
 * platform_config, GANA sobre la env; si no existe (o es null), cae al
 * fallback que pasa el caller (normalmente construido desde las constantes
 * de simple.config.php). La decisión es POR KEY completa — un grupo como
 * 'integration.evolution' es un único jsonb {url,instance,key}: si el admin
 * guardó ese grupo, se usa entero; si no, se usa el fallback entero.
 *
 * Secretos de bootstrap (DB/Redis/S3/JWT/workers) NUNCA pasan por acá — ese
 * es un límite de uso, no algo que esta clase pueda enforcar por sí sola.
 *
 * Cache in-process (una request PHP = un proceso corto): se cargan TODAS las
 * filas en la primera lectura (tabla chica, decenas de keys como mucho) para
 * no hacer una query por cada get() en un request que lee varias keys.
 */
final class PlatformConfig
{
    /** @var array<string, mixed>|null null = todavía no cargado */
    private static ?array $cache = null;

    /**
     * Devuelve el valor guardado para $key, o $envFallback si no hay fila
     * (o su value es JSON null). Nunca lanza — un fallo de DB cae a fallback.
     *
     * Cuando AMBOS (guardado y fallback) son arrays, se hace merge recursivo
     * con el guardado ganando POR CAMPO — un value parcial en platform_config
     * (ej. `flags` sin la subkey `signupOtp`) NO borra el fallback de env de
     * las subkeys ausentes. Sin esto, un POST parcial degradaba SIGNUP_OTP a
     * 'off' en silencio (P1 del review F6: fail-open de seguridad).
     */
    public static function get(string $key, mixed $envFallback = null): mixed
    {
        self::ensureLoaded();
        if (array_key_exists($key, self::$cache) && self::$cache[$key] !== null) {
            $stored = self::$cache[$key];
            if (is_array($stored) && is_array($envFallback)) {
                return array_replace_recursive($envFallback, $stored);
            }
            return $stored;
        }
        return $envFallback;
    }

    /**
     * Upsert de una key completa. $value se serializa a jsonb tal cual
     * (array → objeto/array JSON, string/bool/int → escalar JSON).
     */
    public static function set(string $key, mixed $value, ?string $updatedBy = null): void
    {
        global $db;
        $db->Execute(
            'INSERT INTO platform_config (key, value, updated_by, updated_at)
             VALUES (?, ?::jsonb, ?, now())
             ON CONFLICT (key) DO UPDATE SET
               value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = now()',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE), $updatedBy]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    /** Metadata cruda de una key (value + updated_by + updated_at), o null. */
    public static function getRaw(string $key): ?array
    {
        global $db;
        $r = $db->Execute('SELECT key, value, updated_by, updated_at FROM platform_config WHERE key = ? LIMIT 1', [$key]);
        if (!$r || $r->EOF) {
            return null;
        }
        $f = $r->fields;
        $raw = $f['value'];
        return [
            'key'       => (string) $f['key'],
            'value'     => is_string($raw) ? json_decode($raw, true) : $raw,
            'updatedBy' => $f['updated_by'] ?? null,
            'updatedAt' => $f['updated_at'] ?? null,
        ];
    }

    private static function ensureLoaded(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        global $db;
        try {
            $r = $db->Execute('SELECT key, value FROM platform_config');
            if ($r) {
                while (!$r->EOF) {
                    $raw = $r->fields['value'];
                    self::$cache[(string) $r->fields['key']] = is_string($raw) ? json_decode($raw, true) : $raw;
                    $r->MoveNext();
                }
            }
        } catch (\Throwable $e) {
            error_log('[PlatformConfig] load falló: ' . $e->getMessage());
            self::$cache = [];
        }
    }
}
