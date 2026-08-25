<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Localización DEL TENANT: qué país, qué moneda y qué zona horaria tiene
 * configurado el comercio que está operando.
 *
 * Fuente de verdad única: `company.config` (JSONB) → `settingCountry`,
 * `settingCurrency`, `settingTimeZone`.
 *
 * Por qué existe: el país del tenant se leía suelto en varios lugares
 * (ContactRepository::companyCountry, PaymentsService, etc.) y en muchos
 * otros directamente NO se leía — se asumía 'PY'. Eso hacía que un comercio
 * argentino validara teléfonos como paraguayos y guardara la TZ de Asunción.
 * Con un resolver único, "¿de qué país es este tenant?" se contesta en un
 * solo lugar, se cachea una vez por request y no puede desincronizarse.
 *
 * Complementa a CountryDefaults: éste sabe QUÉ tenant es y qué eligió;
 * aquél sabe qué corresponde a un país cuando el tenant no eligió nada.
 */
final class TenantLocale
{
    /**
     * companyId → ['country' => ?string, 'currency' => ?string, 'timezone' => ?string]
     * Un request nunca mezcla tenants, así que un lookup por companyId alcanza.
     *
     * @var array<string,array{country:?string,currency:?string,timezone:?string}>
     */
    private static array $cache = [];

    /**
     * Precarga el cache con una fila de `company` YA leída.
     *
     * `data.php` trae la fila entera en su query de bootstrap; sin esto,
     * la primera llamada a country()/timezone() dispararía un SELECT
     * redundante en cada request del panel y del POS.
     *
     * @param array|\ArrayAccess $companyRow Fila de `company` con el JSONB `config` aplanado.
     */
    public static function seed(string $companyId, $companyRow): void
    {
        if ($companyId === '') {
            return;
        }

        self::$cache[$companyId] = [
            'country'  => self::str($companyRow['settingCountry']  ?? null),
            'currency' => self::str($companyRow['settingCurrency'] ?? null),
            'timezone' => self::str($companyRow['settingTimeZone'] ?? null),
        ];
    }

    /** ISO-2 del país del tenant ('PY', 'AR'), o null si no lo tiene configurado. */
    public static function country(string $companyId): ?string
    {
        $iso = self::load($companyId)['country'];
        if ($iso === null) {
            return null;
        }
        $iso = strtoupper($iso);
        return preg_match('/^[A-Z]{2}$/', $iso) === 1 ? $iso : null;
    }

    /**
     * Símbolo de moneda para MOSTRAR ('Gs', '$'), tal cual lo configuró el
     * comercio. Si no lo tiene, cae al del país. null si tampoco hay país.
     */
    public static function currencySymbol(string $companyId): ?string
    {
        return self::load($companyId)['currency']
            ?? CountryDefaults::currencySymbol(self::country($companyId));
    }

    /**
     * Código ISO-4217 de la moneda del tenant ('PYG', 'ARS'), o null.
     *
     * Se deriva del PAÍS, no de `settingCurrency`: esa clave guarda el
     * símbolo de display, que no es un código válido para una pasarela ni
     * para un documento fiscal.
     */
    public static function currencyCode(string $companyId): ?string
    {
        return CountryDefaults::currencyCode(self::country($companyId));
    }

    /**
     * Zona horaria IANA del tenant. Cadena de resolución, sin ningún país
     * cableado:
     *   1. `settingTimeZone` del comercio (lo que eligió explícitamente).
     *   2. La TZ que corresponde a su `settingCountry`.
     *   3. La TZ de la PLATAFORMA (env `APP_TIMEZONE`, por default UTC).
     *
     * El paso 3 solo se alcanza con un tenant sin país configurado — un dato
     * roto. UTC es la elección neutral correcta ahí: no pretende ser la hora
     * local de nadie, a diferencia de 'America/Asuncion', que se veía
     * plausible y por eso pasaba desapercibida.
     */
    public static function timezone(string $companyId): string
    {
        $configured = self::load($companyId)['timezone'];
        if (CountryDefaults::isValidTimezone($configured)) {
            return trim((string) $configured);
        }

        $fromCountry = CountryDefaults::timezone(self::country($companyId));
        if ($fromCountry !== null) {
            return $fromCountry;
        }

        return self::platformTimezone();
    }

    /**
     * TZ de la plataforma (no de ningún tenant): el baseline de la sesión de
     * PostgreSQL y de los procesos que corren SIN tenant resuelto (realm
     * /admin, login, signup, crons).
     *
     * Sale de env `APP_TIMEZONE` — es configuración de despliegue, no una
     * constante del código. Sin definir, UTC.
     */
    public static function platformTimezone(): string
    {
        $tz = trim((string) ($_ENV['APP_TIMEZONE'] ?? ''));
        return CountryDefaults::isValidTimezone($tz) ? $tz : 'UTC';
    }

    /** Descarta el cache — solo para tests que crean tenants en el mismo proceso. */
    public static function forget(string $companyId = ''): void
    {
        if ($companyId === '') {
            self::$cache = [];
            return;
        }
        unset(self::$cache[$companyId]);
    }

    /**
     * @return array{country:?string,currency:?string,timezone:?string}
     */
    private static function load(string $companyId): array
    {
        if (isset(self::$cache[$companyId])) {
            return self::$cache[$companyId];
        }

        $empty = ['country' => null, 'currency' => null, 'timezone' => null];
        if ($companyId === '') {
            return $empty;
        }

        $row = ncmExecute(
            "SELECT config->>'settingCountry'  AS country,
                    config->>'settingCurrency' AS currency,
                    config->>'settingTimeZone' AS timezone
               FROM company
              WHERE companyId = ?
              LIMIT 1",
            [$companyId]
        );

        if (!$row) {
            // No cacheamos el miss: un tenant que todavía no existe (alta en
            // curso) tiene que poder resolverse bien en la próxima llamada.
            return $empty;
        }

        return self::$cache[$companyId] = [
            'country'  => self::str($row['country']  ?? null),
            'currency' => self::str($row['currency'] ?? null),
            'timezone' => self::str($row['timezone'] ?? null),
        ];
    }

    /** Normaliza a string no vacío, o null. */
    private static function str($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
