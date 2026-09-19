<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Support\TimeBuckets;

/**
 * "Objetivo semanal" del dashboard (widget `goal`, owner 2026-09-19).
 *
 * ── Qué contesta ────────────────────────────────────────────────────────────
 *
 *   objetivo = la MEJOR semana en ventas de las últimas 12 semanas COMPLETAS
 *              (semana ISO lunes–domingo, en la hora del comercio);
 *   actual   = las ventas de la semana en curso hasta ahora;
 *   ritmo    = cuánto llevaba la mejor semana a la MISMA altura (mismo día de
 *              la semana y misma hora de pared). La comparación justa es
 *              contra el ritmo: un jueves a la tarde no se mide contra una
 *              semana entera.
 *
 * Independiente del rango del dashboard, como "Ahora".
 *
 * ── Venta = la definición del KPI de Ingresos ────────────────────────────────
 *
 * Tipos 0/3/6 no anulados (`SaleFilters::notVoidedSql()`), `total - descuento`,
 * menos las ventas internas si el comercio las ignora (`NonAddingSales`).
 * El parcial de la mejor semana sale directamente de `PeriodStats` —la fórmula
 * única—; las semanas se leen con UNA query agrupada que repite solo el WHERE
 * de `PeriodStats::rawByOutlet()` (agrupar por semana en vez de por sucursal).
 *
 * ── Zona horaria ────────────────────────────────────────────────────────────
 *
 * `transactionDate` es hora de pared del comercio y `TenantClock::apply()` ya
 * fijó la zona de la sesión de Postgres: `date_trunc('week', …)` corta en el
 * lunes del comercio (ver `TimeBuckets`). `$now` llega como hora de pared
 * (`TenantClock::now()`) y toda la aritmética de acá es de pared: se hace en
 * UTC a propósito para que un cambio de horario no corra el "misma altura".
 *
 * ── Cuándo no hay objetivo ──────────────────────────────────────────────────
 *
 * Con menos de `MIN_WEEKS` semanas completas con ventas en la ventana no hay
 * contra qué medir (regla del owner: nada de bloques vacíos) → `null`.
 */
final class WeeklyGoalService
{
    /** Semanas completas que se miran para elegir la mejor. */
    public const WEEKS = 12;
    /** Semanas completas con ventas necesarias para mostrar el objetivo. */
    public const MIN_WEEKS = 4;

    /**
     * @param string $roc Alcance empresa + sucursales (`Roc::build`/`Roc::scoped`).
     * @param string $now Hora de pared del comercio, 'Y-m-d H:i:s' (`TenantClock::now()`).
     * @return array{weekStart:string,current:float,best:array{weekStart:string,weekEnd:string,total:float,atSamePoint:float},weeksWithSales:int}|null
     */
    public function goal(string $roc, string $now): ?array
    {
        $utc   = new \DateTimeZone('UTC');
        $nowDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr(trim($now), 0, 19), $utc);
        if ($nowDt === false) {
            return null;
        }

        $monday      = $nowDt->setTime(0, 0)->modify('-' . ((int) $nowDt->format('N') - 1) . ' days');
        $windowStart = $monday->modify('-' . self::WEEKS . ' weeks');
        $from        = $windowStart->format('Y-m-d H:i:s');
        $to          = $nowDt->format('Y-m-d H:i:s');

        $weeks = $this->netByWeek($from, $to, $roc);

        $thisWeek       = $monday->format('Y-m-d');
        $bestKey        = null;
        $bestTotal      = 0.0;
        $weeksWithSales = 0;
        foreach ($weeks as $key => $w) {
            if ($key >= $thisWeek || $key < $windowStart->format('Y-m-d')) {
                continue;
            }
            if ($w['count'] > 0) {
                $weeksWithSales++;
            }
            // Empate: gana la más reciente (las claves llegan en orden).
            if ($w['total'] > 0 && $w['total'] >= $bestTotal) {
                $bestTotal = $w['total'];
                $bestKey   = $key;
            }
        }

        if ($weeksWithSales < self::MIN_WEEKS || $bestKey === null) {
            return null;
        }

        // Misma altura: mismo día ISO de la semana y misma hora de pared.
        $bestMonday = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $bestKey . ' 00:00:00', $utc);
        $bestAtNow  = $bestMonday
            ->modify('+' . ((int) $nowDt->format('N') - 1) . ' days')
            ->setTime((int) $nowDt->format('G'), (int) $nowDt->format('i'), (int) $nowDt->format('s'));
        $atSamePoint = PeriodStats::compute(PeriodStats::sum(PeriodStats::rawByOutlet(
            $bestMonday->format('Y-m-d H:i:s'),
            $bestAtNow->format('Y-m-d H:i:s'),
            $roc
        )))['total'];

        return [
            'weekStart'      => $thisWeek,
            'current'        => (float) ($weeks[$thisWeek]['total'] ?? 0.0),
            'best'           => [
                'weekStart'   => $bestKey,
                'weekEnd'     => $bestMonday->modify('+6 days')->format('Y-m-d'),
                'total'       => $bestTotal,
                'atSamePoint' => (float) $atSamePoint,
            ],
            'weeksWithSales' => $weeksWithSales,
        ];
    }

    /**
     * Ventas netas por semana ISO del rango: UNA query agrupada + las internas
     * por semana. Clave = lunes 'Y-m-d', en orden cronológico.
     *
     * @return array<string, array{total:float,count:int}>
     */
    private function netByWeek(string $from, string $to, string $roc): array
    {
        $tb = TimeBuckets::forRange($from, $to);
        if ($tb->granularity !== TimeBuckets::WEEK) {
            // La ventana (12 semanas + la actual) cae siempre en el grano
            // semanal de `TimeBuckets`; si esa regla cambia, esto no puede
            // seguir agrupando por otra cosa en silencio.
            throw new \LogicException('WeeklyGoalService: la ventana no resolvió a grano semanal');
        }
        $bucket = $tb->sql('transactionDate');

        $out = [];
        $rs  = ncmExecute(
            "SELECT $bucket AS wk, SUM(transactionTotal) AS total, SUM(transactionDiscount) AS discount,
                    COUNT(transactionId) AS count
             FROM transaction WHERE transactionType IN (0,3,6)
             AND " . SaleFilters::notVoidedSql() . "
             AND transactionDate >= ? AND transactionDate <= ?" . $roc . "
             GROUP BY 1 ORDER BY 1",
            [$from, $to], false, true
        );
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f   = $rs->fields;
                $key = (string) ($f['wk'] ?? '');
                $out[$key] = [
                    'total' => (float) ($f['total'] ?? 0) - (float) ($f['discount'] ?? 0),
                    'count' => (int) ($f['count'] ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        foreach (NonAddingSales::internalTotalsByOutletAndBucket($roc, $from, $to, $tb) as $byWeek) {
            foreach ($byWeek as $key => $internal) {
                if (isset($out[$key])) {
                    $out[$key]['total'] -= (float) $internal;
                }
            }
        }

        ksort($out);
        return $out;
    }
}
