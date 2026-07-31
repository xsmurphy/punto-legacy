<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Hora "ahora" en la zona horaria del tenant, como string naive
 * 'Y-m-d H:i:s' (convención de storage: timestamps tenant-local SIN
 * timezone — la sesión de PG los interpreta como hora local).
 *
 * Los endpoints legacy bajo /app y /panel heredan esto gratis porque
 * data.php hace `date_default_timezone_set($setting['settingTimeZone'])`
 * al bootear el request. La API REST nueva (api/v1/*, api/lib/Finance/*,
 * etc.) NO pasa por data.php — su bootstrap (api/bootstrap.php → head.php)
 * nunca toca el default timezone del proceso, que en el container de
 * prod queda en UTC. Cualquier `date('Y-m-d H:i:s')` / `new DateTimeImmutable('now')`
 * ahí adentro queda +3h adelantado (incidente 2026-07-30: gastos/cheques/
 * cuotas de crédito se intercalaban mal en "últimos movimientos" de
 * /finanzas — la fecha quedaba por delante de eventos posteriores).
 *
 * Resolver: SIEMPRE `TenantClock::now($companyId)` en vez de `date(...)`
 * para cualquier write nuevo bajo `api/lib` o `api/v1` que no pase por
 * `data.php`. No muta el default timezone del proceso (evita pisar otras
 * requests si el runtime llegara a reusar el proceso, ej. workers
 * persistentes) — resuelve la TZ vía DateTimeZone explícita por llamada.
 */
final class TenantClock
{
    private const FALLBACK_TZ = 'America/Asuncion';

    /** @var array<string,string> companyId → IANA timezone, cacheado por request */
    private static array $tzCache = [];

    /** 'now' del tenant, formateado 'Y-m-d H:i:s' (naive, sin offset). */
    public static function now(string $companyId): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::timezone($companyId))))
            ->format('Y-m-d H:i:s');
    }

    /** Timezone IANA configurada del tenant (`company.config->>'settingTimeZone'`), con fallback. */
    public static function timezone(string $companyId): string
    {
        if (isset(self::$tzCache[$companyId])) {
            return self::$tzCache[$companyId];
        }

        $tz = self::FALLBACK_TZ;
        if ($companyId !== '') {
            $row = ncmExecute(
                "SELECT config->>'settingTimeZone' AS tz FROM company WHERE companyId = ?",
                [$companyId]
            );
            $candidate = (string) ($row['tz'] ?? '');
            // Mismo guard que data.php: settingTimeZone puede venir vacío/inválido.
            if ($candidate !== '' && in_array($candidate, \DateTimeZone::listIdentifiers(), true)) {
                $tz = $candidate;
            }
        }

        return self::$tzCache[$companyId] = $tz;
    }
}
