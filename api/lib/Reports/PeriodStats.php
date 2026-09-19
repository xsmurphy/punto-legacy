<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Ventas, egresos, ganancia y margen de un período — la fórmula ÚNICA del
 * dashboard del panel (KPIs de "Ingresos", "Ganancia", "Margen", "Ticket
 * promedio"), expresada por SUCURSAL.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 *
 * El dashboard calculaba sus KPIs con una query sin agrupar
 * (`DashboardService::periodStats()`) y "Ventas por sucursal" con otra query
 * agrupada que repetía el mismo SQL. El reporte de Sucursales (2026-09-19)
 * necesita los MISMOS números por sucursal, y un tercer SQL copiado es cómo
 * dos pantallas enlazadas terminan diciendo cosas distintas.
 *
 * Así que el dato crudo se lee UNA vez, agrupado por `outletId`
 * (`rawByOutlet()`), y todo lo demás sale de ahí:
 *   - el KPI del dashboard = `compute(sum(rawByOutlet()))`;
 *   - la fila de una sucursal = `compute(raw de esa sucursal)`;
 *   - el total del reporte de sucursales = el mismo `compute(sum(...))`.
 * La suma de las sucursales cierra con el dashboard POR CONSTRUCCIÓN, no por
 * casualidad (`transaction.outletId` es NOT NULL: no hay filas sin sucursal
 * que el total cuente y las filas no).
 *
 * ── Definiciones (heredadas tal cual del dashboard) ──────────────────────────
 *
 *   ventas   = SUM(total) - SUM(descuento) de tipos 0/3/6 no anulados,
 *              menos las ventas internas (`NonAddingSales`, si el comercio
 *              las ignora).
 *   egresos  = SUM(total) de tipos 1/4 con `transactionStatus = 1`.
 *   ganancia = ventas - egresos.
 *   margen   = ganancia / ventas × 100, piso 0, redondeado; 100 cuando no
 *              hubo egresos o no hubo ventas (así lo mostraba el dashboard y
 *              así se sigue mostrando: no se cambia una definición al moverla).
 *   ticket   = SUM(total) BRUTO / cantidad de ventas.
 *
 * Los egresos NO son costo de mercadería: es "lo que entró menos lo que salió"
 * en el período, la misma ganancia que el dashboard.
 */
final class PeriodStats
{
    /** @return array{gross:float,discount:float,count:int,internal:float,expenses:float} */
    public static function emptyRaw(): array
    {
        return ['gross' => 0.0, 'discount' => 0.0, 'count' => 0, 'internal' => 0.0, 'expenses' => 0.0];
    }

    /**
     * Dato crudo del período por sucursal. Dos queries agrupadas (ventas y
     * egresos) + la lectura de ventas internas, sin importar cuántas sucursales
     * haya. `$roc` acota empresa y sucursales (`Roc::build`/`Roc::scoped`).
     *
     * @return array<string, array{gross:float,discount:float,count:int,internal:float,expenses:float}>
     */
    public static function rawByOutlet(string $from, string $to, string $roc): array
    {
        $out = [];

        $sales = ncmExecute(
            "SELECT outletId AS \"outletId\", SUM(transactionTotal) AS total, SUM(transactionDiscount) AS discount,
                    COUNT(transactionId) AS count
             FROM transaction WHERE transactionType IN (0,3,6)
             AND " . SaleFilters::notVoidedSql() . "
             AND transactionDate >= ? AND transactionDate <= ?" . $roc . "
             GROUP BY outletId",
            [$from, $to], false, true
        );
        foreach (self::iterate($sales) as $f) {
            $oid = (string) ($f['outletId'] ?? $f['outletid'] ?? '');
            $out[$oid] = self::emptyRaw();
            $out[$oid]['gross']    = (float) ($f['total'] ?? 0);
            $out[$oid]['discount'] = (float) ($f['discount'] ?? 0);
            $out[$oid]['count']    = (int) ($f['count'] ?? 0);
        }

        foreach (NonAddingSales::internalTotalsByOutlet($roc, $from, $to) as $oid => $internal) {
            $out[$oid] ??= self::emptyRaw();
            $out[$oid]['internal'] = (float) $internal;
        }

        $expenses = ncmExecute(
            "SELECT outletId AS \"outletId\", SUM(transactionTotal) AS total FROM transaction
             WHERE transactionType IN (1,4) AND transactionDate >= ? AND transactionDate <= ?
             AND transactionStatus = 1" . $roc . "
             GROUP BY outletId",
            [$from, $to], false, true
        );
        foreach (self::iterate($expenses) as $f) {
            $oid = (string) ($f['outletId'] ?? $f['outletid'] ?? '');
            $out[$oid] ??= self::emptyRaw();
            $out[$oid]['expenses'] = (float) ($f['total'] ?? 0);
        }

        return $out;
    }

    /**
     * Suma de crudos. Todos los campos son aditivos a propósito: lo que NO
     * suma (margen, ticket) se calcula recién en `compute()`.
     *
     * @param iterable<array{gross:float,discount:float,count:int,internal:float,expenses:float}> $raws
     * @return array{gross:float,discount:float,count:int,internal:float,expenses:float}
     */
    public static function sum(iterable $raws): array
    {
        $acc = self::emptyRaw();
        foreach ($raws as $r) {
            $acc['gross']    += (float) $r['gross'];
            $acc['discount'] += (float) $r['discount'];
            $acc['count']    += (int) $r['count'];
            $acc['internal'] += (float) $r['internal'];
            $acc['expenses'] += (float) $r['expenses'];
        }
        return $acc;
    }

    /**
     * Los KPIs a partir del crudo — la fórmula del dashboard, sin tocar.
     *
     * @param array{gross:float,discount:float,count:int,internal:float,expenses:float} $raw
     * @return array{total:float,expenses:float,revenue:float,margin:float,count:int,customerAverage:float}
     */
    public static function compute(array $raw): array
    {
        $finalTotal    = ((float) $raw['gross'] - (float) $raw['discount']) - (float) $raw['internal'];
        $count         = (int) $raw['count'];
        $totalExpenses = (float) $raw['expenses'];
        $revenue       = $finalTotal - $totalExpenses;
        $margin        = ($finalTotal > 0 && $totalExpenses > 0) ? max(0, ($revenue / $finalTotal) * 100) : 100;

        return [
            'total'           => $finalTotal,
            'expenses'        => $totalExpenses,
            'revenue'         => $revenue,
            'margin'          => round($margin),
            'count'           => $count,
            'customerAverage' => $count ? ((float) $raw['gross'] / $count) : 0.0,
        ];
    }

    /** ¿Hay algo contra qué comparar? El mismo criterio de `previous` del dashboard. */
    public static function hasData(array $stats): bool
    {
        return (int) $stats['count'] > 0 || (float) $stats['expenses'] > 0;
    }

    /** @return \Generator<array<string,mixed>> */
    private static function iterate(mixed $rs): \Generator
    {
        if (!$rs || !is_object($rs)) {
            return;
        }
        while (!$rs->EOF) {
            yield $rs->fields;
            $rs->MoveNext();
        }
        $rs->Close();
    }
}
