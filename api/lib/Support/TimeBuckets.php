<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Granularidad de las series temporales de los reportes — la regla ÚNICA.
 *
 * ── El defecto que cierra ────────────────────────────────────────────────────
 *
 * Cada gráfico con fechas en el eje agrupaba por DÍA sin importar el rango:
 * un año eran 365 barras y el gráfico dejaba de leerse (dashboard "Margen,
 * Ingresos y Egresos", "Nuevos vs recurrentes" de clientes). La decisión del
 * owner (2026-09-19) es que el grano sale del LARGO del rango:
 *
 *   ≤ 31 días   → día
 *   32–120 días → semana ISO (lunes a domingo)
 *   > 120 días  → mes
 *
 * Vive acá, una vez, para que ningún endpoint decida su propio corte y dos
 * gráficos del mismo período no hablen en granos distintos. La agregación la
 * hace el SERVIDOR: el navegador recibe los buckets ya armados.
 *
 * ── Zona horaria ────────────────────────────────────────────────────────────
 *
 * Nada de `AT TIME ZONE` acá. `TenantClock::apply()` fija la zona del tenant
 * en la sesión de Postgres y en el default de PHP antes de que corra cualquier
 * reporte, así que `date_trunc(...)` en SQL y `keyFor()` en PHP cortan en la
 * MISMA hora de pared del comercio (ver `Date::reportRange()`).
 *
 * ── Qué es un bucket ────────────────────────────────────────────────────────
 *
 * Se identifica por la fecha de su PRIMER día natural (`bucket`, 'Y-m-d': el
 * lunes de la semana, el 1 del mes) y trae el ÚLTIMO día natural (`end`). Los
 * de los bordes pueden quedar recortados por el rango —una semana que empieza
 * antes del `from`, un mes que termina después del `to`—: esos llevan
 * `partial: true` para que el gráfico no compare un mes de 10 días contra uno
 * de 30 como si fueran lo mismo. Un día nunca es parcial.
 *
 * La lista de buckets es el calendario COMPLETO del rango, con los vacíos
 * incluidos: un hueco en el eje se lee como "no hay dato", no como "cero".
 */
final class TimeBuckets
{
    public const DAY   = 'day';
    public const WEEK  = 'week';
    public const MONTH = 'month';

    /** Hasta acá (inclusive) el grano es el día. */
    public const DAILY_MAX_DAYS = 31;
    /** Hasta acá (inclusive) el grano es la semana; más, el mes. */
    public const WEEKLY_MAX_DAYS = 120;

    /**
     * Tope de buckets que se enumeran. Con la regla de arriba un rango normal
     * da como mucho 120 semanas o unos pocos cientos de meses; esto solo corta
     * un rango absurdo mandado a mano por la API para que no devuelva un array
     * sin límite.
     */
    private const MAX_BUCKETS = 1000;

    private function __construct(
        public readonly string $granularity,
        /** Primer día del rango, 'Y-m-d'. */
        public readonly string $fromDate,
        /** Último día del rango, 'Y-m-d'. */
        public readonly string $toDate,
    ) {}

    /**
     * Resuelve la granularidad de un rango de reporte. `from`/`to` son los que
     * devuelve `Date::reportRange()` ('Y-m-d H:i:s'); solo cuenta la FECHA.
     */
    public static function forRange(string $from, string $to): self
    {
        $fromDate = substr(trim($from), 0, 10);
        $toDate   = substr(trim($to), 0, 10);

        return new self(self::granularityForDays(self::daysInRange($fromDate, $toDate)), $fromDate, $toDate);
    }

    /** Días de calendario del rango, contando los dos extremos. Rango invertido → 0. */
    public static function daysInRange(string $fromDate, string $toDate): int
    {
        $a = self::date($fromDate);
        $b = self::date($toDate);
        if ($a === null || $b === null || $b < $a) {
            return 0;
        }
        return (int) $a->diff($b)->days + 1;
    }

    /** La regla del owner, pura. */
    public static function granularityForDays(int $days): string
    {
        if ($days <= self::DAILY_MAX_DAYS) {
            return self::DAY;
        }
        return $days <= self::WEEKLY_MAX_DAYS ? self::WEEK : self::MONTH;
    }

    /**
     * Expresión SQL que devuelve la clave del bucket ('YYYY-MM-DD' del primer
     * día) de una columna fecha/timestamp. `$column` es SIEMPRE un nombre de
     * columna escrito en el código, nunca input del request.
     *
     * `date_trunc('week', …)` es semana ISO (lunes), igual que `keyFor()`.
     * Sirve igual sobre una columna `date` (un rollup de grano día): Postgres
     * la promueve a timestamp en la zona de la sesión y la trunca ahí.
     */
    public function sql(string $column): string
    {
        return match ($this->granularity) {
            self::WEEK  => "to_char(date_trunc('week', $column), 'YYYY-MM-DD')",
            self::MONTH => "to_char(date_trunc('month', $column), 'YYYY-MM-DD')",
            default     => "to_char($column, 'YYYY-MM-DD')",
        };
    }

    /**
     * Equivalente en PHP de `sql()`: la clave del bucket de una fecha. Para las
     * series que se agregan sobre filas ya leídas (la primera compra de un
     * cliente, la salida que cierra un turno). Solo mira la parte de FECHA del
     * valor, que ya viene en la hora del comercio.
     */
    public function keyFor(string $date): string
    {
        $d = self::date(substr(trim($date), 0, 10));
        if ($d === null) {
            return '';
        }
        return $this->startOf($d)->format('Y-m-d');
    }

    /**
     * El calendario completo del rango.
     *
     * @return list<array{bucket: string, end: string, partial: bool}>
     */
    public function buckets(): array
    {
        $from = self::date($this->fromDate);
        $to   = self::date($this->toDate);
        if ($from === null || $to === null || $to < $from) {
            return [];
        }

        $out = [];
        for ($start = $this->startOf($from); $start <= $to && count($out) < self::MAX_BUCKETS; $start = $this->next($start)) {
            $end   = $this->next($start)->modify('-1 day');
            $out[] = [
                'bucket'  => $start->format('Y-m-d'),
                'end'     => $end->format('Y-m-d'),
                'partial' => $start < $from || $end > $to,
            ];
        }
        return $out;
    }

    /**
     * Arma la serie final: un punto por bucket del calendario, con los valores
     * que haya en `$valuesByKey` (indexado por clave de bucket) y `$zero` en
     * los que no. Los valores no pisan `bucket`/`end`/`partial`.
     *
     * @param array<string, array<string, mixed>> $valuesByKey
     * @param array<string, mixed>                 $zero
     * @return list<array<string, mixed>>
     */
    public function fill(array $valuesByKey, array $zero): array
    {
        $out = [];
        foreach ($this->buckets() as $b) {
            $out[] = array_merge($zero, $valuesByKey[$b['bucket']] ?? [], $b);
        }
        return $out;
    }

    private function startOf(\DateTimeImmutable $d): \DateTimeImmutable
    {
        return match ($this->granularity) {
            // 'N' = día ISO (1 = lunes … 7 = domingo).
            self::WEEK  => $d->modify('-' . ((int) $d->format('N') - 1) . ' days'),
            self::MONTH => $d->modify('first day of this month'),
            default     => $d,
        };
    }

    private function next(\DateTimeImmutable $start): \DateTimeImmutable
    {
        return match ($this->granularity) {
            self::WEEK  => $start->modify('+7 days'),
            self::MONTH => $start->modify('first day of next month'),
            default     => $start->modify('+1 day'),
        };
    }

    /**
     * Una fecha 'Y-m-d' a medianoche, en UTC a propósito: acá solo se hace
     * aritmética de CALENDARIO (sumar días, ir al lunes), y en UTC un día dura
     * siempre 24 h — en una zona con horario de verano, "+1 day" sobre una
     * medianoche que no existe correría la fecha.
     */
    private static function date(string $ymd): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone('UTC'));
        return $d !== false && $d->format('Y-m-d') === $ymd ? $d : null;
    }
}
