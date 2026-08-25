<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Hora "ahora" en la zona horaria del tenant, como string naive
 * 'Y-m-d H:i:s' (convención de storage: timestamps tenant-local SIN
 * timezone — la sesión de PG los interpreta como hora local).
 *
 * Los endpoints legacy bajo /app y /panel heredan esto gratis porque
 * data.php llama `TenantClock::apply()` al bootear el request. La API REST
 * nueva (api/v1/*, api/lib/Finance/*, etc.) también pasa por ahí
 * (apiAuthTenant → data.php), pero los procesos SIN tenant (crons, realm
 * /admin) no: en el container de prod el default del proceso queda en UTC.
 * Cualquier `date('Y-m-d H:i:s')` / `new DateTimeImmutable('now')` ahí
 * adentro queda desfasado respecto de la hora del comercio (incidente
 * 2026-07-30: gastos/cheques/cuotas de crédito se intercalaban mal en
 * "últimos movimientos" de /finanzas — la fecha quedaba por delante de
 * eventos posteriores).
 *
 * Resolver: SIEMPRE `TenantClock::now($companyId)` en vez de `date(...)`
 * para cualquier write nuevo bajo `api/lib` o `api/v1` que no pase por
 * `data.php`. No muta el default timezone del proceso (evita pisar otras
 * requests si el runtime llegara a reusar el proceso, ej. workers
 * persistentes) — resuelve la TZ vía DateTimeZone explícita por llamada.
 *
 * La TZ ya NO tiene fallback cableado a Paraguay: la resuelve TenantLocale
 * (settingTimeZone → TZ del país del tenant → TZ de la plataforma).
 */
final class TenantClock
{
    /** @var array<string,bool> companyId cuya TZ ya se aplicó al proceso+sesión PG. */
    private static array $applied = [];

    /** 'now' del tenant, formateado 'Y-m-d H:i:s' (naive, sin offset). */
    public static function now(string $companyId): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::timezone($companyId))))
            ->format('Y-m-d H:i:s');
    }

    /** Timezone IANA del tenant. Ver TenantLocale::timezone() para la cadena de resolución. */
    public static function timezone(string $companyId): string
    {
        return TenantLocale::timezone($companyId);
    }

    /**
     * Aplica la TZ del tenant al request entero: default del proceso PHP
     * (`date()`, `strtotime()`) y sesión de PostgreSQL (rendering de
     * `timestamptz`, `::date`, `date_trunc`, `now()::timestamp`).
     *
     * Se llama UNA vez por request desde `data.php`, que es el punto donde
     * el tenant recién queda resuelto. Antes de eso la sesión de PG arranca
     * con la TZ de la plataforma (ver `includes/db.php`), porque ahí todavía
     * no se sabe de qué comercio es la request.
     *
     * Es importante que ambos lados —PHP y PG— queden en la MISMA zona: un
     * `date('Y-m-d')` de PHP y un `transactiondate::date` de PG tienen que
     * cortar el día en el mismo instante, o una venta de las 23:30 aparece
     * en días distintos según quién calcule el corte.
     *
     * @return string La TZ IANA aplicada.
     */
    public static function apply(string $companyId): string
    {
        $tz = self::timezone($companyId);

        date_default_timezone_set($tz);
        self::applyToDatabaseSession($tz);

        self::$applied[$companyId] = true;

        return $tz;
    }

    /**
     * `SET TIME ZONE` en la conexión abierta.
     *
     * PostgreSQL no acepta parámetros en `SET`, así que la TZ se interpola
     * en el SQL. Por eso se valida contra la lista IANA de PHP ANTES:
     * lo que llega acá sale de `company.config`, que es dato editable por
     * el tenant desde Ajustes — sin este guard sería una inyección directa.
     */
    private static function applyToDatabaseSession(string $tz): void
    {
        if (!CountryDefaults::isValidTimezone($tz)) {
            return;
        }

        global $db;
        if (!isset($db) || !is_object($db) || !method_exists($db, 'Execute')) {
            return;
        }

        $db->Execute("SET TIME ZONE '" . $tz . "'");
    }
}
