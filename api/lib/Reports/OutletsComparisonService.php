<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Support\TimeBuckets;
use Punto\App\Helpers\Date;

/**
 * Reporte de Sucursales — cómo rinde cada sucursal contra las otras
 * (`/reports/outlets`, 2026-09-19).
 *
 * ── Ninguna definición propia ───────────────────────────────────────────────
 *
 * Todos los números salen de lo que ya usan el dashboard y los reportes:
 *   - ventas / ganancia / margen / ticket / cantidad → `PeriodStats`, la
 *     misma lectura del KPI del dashboard. La fila total ES el KPI.
 *   - clientes activos → `CustomersService::activeByOutlet()`, la definición
 *     de `kpis()` (el total es el distinct, no la suma).
 *   - evolución → mismo "venta" que `PeriodStats` (tipos 0/3/6 no anulados,
 *     menos descuento y ventas internas), cortado con `TimeBuckets`.
 *   - horas pico → la definición de `topHours` del dashboard (cantidad de
 *     ventas 0/3 no anuladas por hora).
 *   - medios de pago → `NonAddingSales::salesByPaymentByOutlet()`, la misma
 *     lectura que el reporte de medios de pago.
 *   - top artículos → la definición de `topItems` del dashboard (unidades
 *     vendidas en ventas 0/3 no anuladas, líneas con total > 0).
 *
 * ── Alcance ─────────────────────────────────────────────────────────────────
 *
 * `$outletIds` es el LÍMITE del usuario (`OutletScope::current()`: `[]` =
 * todo el tenant), no la sucursal que tenga elegida en el selector del logo:
 * el reporte existe para comparar sucursales entre sí, y con una sola no hay
 * nada que comparar. Una sucursal fuera del límite no aparece ni en las filas
 * ni en el total. Cada lectura es UNA query agrupada por `outletId`, nunca una
 * por sucursal.
 *
 * ── Qué sucursales son filas ────────────────────────────────────────────────
 *
 * Las ACTIVAS del alcance (aunque no hayan vendido: "no vendió nada" es un
 * dato de comparación) más las inactivas que tuvieron movimiento en el período
 * (si no, el total no cerraría con la suma de las filas).
 */
final class OutletsComparisonService
{
    private const TOP_ITEMS = 5;

    /**
     * Tabla comparativa: una fila por sucursal + la fila total, cada una con
     * su período anterior (mismo largo, inmediatamente anterior —
     * `Date::previousRange()`). `previous` es null cuando esa sucursal (o el
     * alcance entero, para el total) no tuvo NINGÚN movimiento en el período
     * anterior: sin base no hay delta.
     *
     * @param list<string> $outletIds
     */
    public function summary(string $from, string $to, string $companyId, array $outletIds): array
    {
        $roc = Roc::scoped($companyId, $outletIds);
        [$pFrom, $pTo] = Date::previousRange($from, $to);

        $current  = PeriodStats::rawByOutlet($from, $to, $roc);
        $previous = PeriodStats::rawByOutlet($pFrom, $pTo, $roc);
        $active   = (new CustomersService())->activeByOutlet($from, $to, $companyId, $outletIds);

        $outlets = $this->outlets($companyId, $outletIds, array_keys($current));

        $rows = [];
        foreach ($outlets as $oid => $o) {
            $stats  = PeriodStats::compute($current[$oid] ?? PeriodStats::emptyRaw());
            $prev   = PeriodStats::compute($previous[$oid] ?? PeriodStats::emptyRaw());
            $rows[] = [
                'outletId'        => $oid,
                'name'            => $o['name'],
                'active'          => $o['active'],
                'activeCustomers' => (int) ($active['byOutlet'][$oid] ?? 0),
                'previous'        => PeriodStats::hasData($prev) ? self::prevShape($prev) : null,
            ] + $stats;
        }
        usort($rows, static fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        $total     = PeriodStats::compute(PeriodStats::sum($current));
        $prevTotal = PeriodStats::compute(PeriodStats::sum($previous));

        return [
            'rows'  => $rows,
            'total' => $total + [
                'activeCustomers' => $active['total'],
                'previous'        => PeriodStats::hasData($prevTotal) ? self::prevShape($prevTotal) : null,
            ],
        ];
    }

    /**
     * Ventas por sucursal en el tiempo, con el grano de `TimeBuckets` y el
     * calendario completo (vacíos en 0, bordes `partial`). La suma de los
     * puntos de una sucursal da su `total` de `summary()`.
     *
     * @param list<string> $outletIds
     */
    public function series(string $from, string $to, string $companyId, array $outletIds): array
    {
        $roc = Roc::scoped($companyId, $outletIds);
        $tb  = TimeBuckets::forRange($from, $to);

        $rows = ncmRows(
            "SELECT outletId AS oid, " . $tb->sql('transactionDate') . " AS bucket,
                    COALESCE(SUM(transactionTotal), 0) - COALESCE(SUM(transactionDiscount), 0) AS total
               FROM transaction
              WHERE transactionType IN (0,3,6)
                AND " . SaleFilters::notVoidedSql() . "
                AND transactionDate >= ? AND transactionDate <= ?" . $roc . "
              GROUP BY outletId, bucket",
            [$from, $to]
        );

        $byOutlet = [];
        foreach ($rows as $r) {
            $byOutlet[(string) $r['oid']][(string) $r['bucket']] = (float) $r['total'];
        }
        foreach (NonAddingSales::internalTotalsByOutletAndBucket($roc, $from, $to, $tb) as $oid => $buckets) {
            foreach ($buckets as $key => $internal) {
                $byOutlet[$oid][$key] = ($byOutlet[$oid][$key] ?? 0.0) - $internal;
            }
        }

        $outlets = $this->outlets($companyId, $outletIds, array_keys($byOutlet));
        $series  = [];
        foreach ($outlets as $oid => $o) {
            $values = [];
            foreach ($byOutlet[$oid] ?? [] as $key => $v) {
                $values[$key] = ['total' => $v];
            }
            $series[] = [
                'outletId' => $oid,
                'name'     => $o['name'],
                'points'   => array_map(
                    static fn (array $p): array => ['bucket' => $p['bucket'], 'total' => (float) $p['total']],
                    $tb->fill($values, ['total' => 0.0])
                ),
            ];
        }

        return [
            'granularity' => $tb->granularity,
            'buckets'     => $tb->buckets(),
            'series'      => $series,
        ];
    }

    /**
     * Diferencias de operación por sucursal: horas pico, mix de medios de pago
     * y top artículos. Tres queries agrupadas por sucursal en total.
     *
     * @param list<string> $outletIds
     */
    public function operations(string $from, string $to, string $companyId, array $outletIds): array
    {
        $roc = Roc::scoped($companyId, $outletIds);

        // Horas: definición de `topHours` del dashboard, las 24 por sucursal.
        $hours = [];
        foreach (ncmRows(
            "SELECT outletId AS oid, EXTRACT(HOUR FROM transactionDate)::int AS hora, COUNT(transactionId) AS n
               FROM transaction
              WHERE transactionType IN (0,3) AND " . SaleFilters::notVoidedSql() . "
                AND transactionDate BETWEEN ? AND ?" . $roc . "
              GROUP BY outletId, hora",
            [$from, $to]
        ) as $r) {
            $hours[(string) $r['oid']][] = ['hour' => (int) $r['hora'], 'count' => (int) $r['n']];
        }

        // Medios de pago: la lectura del reporte de medios de pago.
        $payments = [];
        foreach (NonAddingSales::salesByPaymentByOutlet($from, $to, $roc) as $oid => $group) {
            foreach ($group as $m) {
                $amount = (float) ($m['price'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                // `groupByPaymentMethod()` ya resolvió el nombre legible.
                $payments[(string) $oid][] = [
                    'name'   => (string) ($m['name'] ?? $m['type'] ?? ''),
                    'amount' => $amount,
                ];
            }
        }

        // Top artículos: definición de `topItems` del dashboard, los primeros
        // N de CADA sucursal con una ventana, no N queries. `itemSoldDate` va
        // además del rango de la venta: es la clave de partición de `itemSold`
        // (siempre = fecha de la venta) y sin él se recorren todas.
        $rocB  = Roc::scoped($companyId, $outletIds, 'b');
        $items = [];
        foreach (ncmRows(
            "SELECT oid, itemid, name, units, total FROM (
                 SELECT b.outletId AS oid, a.itemId AS itemid, MAX(i.itemName) AS name,
                        SUM(a.itemSoldUnits) AS units, SUM(a.itemSoldTotal) AS total,
                        ROW_NUMBER() OVER (PARTITION BY b.outletId ORDER BY SUM(a.itemSoldUnits) DESC, SUM(a.itemSoldTotal) DESC) AS rn
                   FROM itemSold a
                   JOIN transaction b ON b.transactionId = a.transactionId
                   LEFT JOIN item i ON i.itemId = a.itemId AND i.companyId = b.companyId
                  WHERE b.transactionType IN (0,3) AND " . SaleFilters::notVoidedSql('b') . "
                    AND b.transactionDate BETWEEN ? AND ?
                    AND a.itemSoldDate BETWEEN ? AND ?" . $rocB . "
                    AND a.itemSoldTotal > 0
                  GROUP BY b.outletId, a.itemId
             ) x
             WHERE rn <= " . self::TOP_ITEMS . "
             ORDER BY oid, rn",
            [$from, $to, $from, $to]
        ) as $r) {
            $items[(string) $r['oid']][] = [
                'itemId' => (string) $r['itemid'],
                'name'   => (string) ($r['name'] ?? ''),
                'units'  => (float) $r['units'],
                'total'  => (float) $r['total'],
            ];
        }

        $outlets = $this->outlets($companyId, $outletIds, array_unique(array_merge(
            array_keys($hours), array_keys($payments), array_keys($items)
        )));

        $rows = [];
        foreach ($outlets as $oid => $o) {
            $h = $hours[$oid] ?? [];
            usort($h, static fn (array $a, array $b): int => [$b['count'], $a['hour']] <=> [$a['count'], $b['hour']]);
            $p = $payments[$oid] ?? [];
            usort($p, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
            $rows[] = [
                'outletId' => $oid,
                'name'     => $o['name'],
                'hours'    => $h,
                'payments' => $p,
                'topItems' => $items[$oid] ?? [],
            ];
        }
        return ['rows' => $rows];
    }

    /**
     * Sucursales que son filas del reporte, ordenadas por nombre: las activas
     * del alcance + las que tuvieron movimiento (`$withData`, ya acotadas por
     * `$roc`). Nombres leídos con `companyId` bindeado.
     *
     * @param list<string> $outletIds
     * @param list<string|int> $withData
     * @return array<string, array{name:string, active:bool}>
     */
    private function outlets(string $companyId, array $outletIds, array $withData): array
    {
        $withData = array_values(array_filter(array_map('strval', $withData), static fn (string $id): bool => $id !== ''));
        $params   = [$companyId];
        $dataCond = '';
        if ($withData !== []) {
            $dataCond = ' OR outletId IN (' . implode(',', array_fill(0, count($withData), '?')) . ')';
            $params   = array_merge($params, $withData);
        }

        $rows = ncmRows(
            "SELECT outletId AS oid, outletName AS name, outletStatus AS status
               FROM outlet
              WHERE companyId = ?" . \Punto\Api\Outlets\OutletScope::sqlFilter('outletId', $outletIds) . "
                AND (outletStatus = 1" . $dataCond . ")
              ORDER BY outletName ASC",
            $params
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['oid']] = [
                'name'   => (string) ($r['name'] ?? ''),
                'active' => (int) ($r['status'] ?? 0) === 1,
            ];
        }
        return $out;
    }

    /** @param array{total:float,revenue:float,count:int} $stats */
    private static function prevShape(array $stats): array
    {
        return ['total' => $stats['total'], 'revenue' => $stats['revenue'], 'count' => $stats['count']];
    }
}
