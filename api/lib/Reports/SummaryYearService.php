<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Resumen Anual de Ingresos y Egresos (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportSummaryYearService.php (Fase 2 batch 7). Cambios vs original:
 *  - namespace + `final`
 *  - el ROC se recibe por PARÁMETRO (no `getROC(1)` interno)
 *  - `getNonAddingToSales` (sólo en panel, cadena profunda con `getSalesByPayment` +
 *    `lessInternalTotals` que en /app están latente rotos en PG) → reemplazado por
 *    `NonAddingSales::compute()` (helper compartido del namespace).
 *
 * Por cada mes del año con ventas: agregados de ventas (tipos 0,3) + gastos (1,4) +
 * devoluciones (6, magnitud) + clientes nuevos (contact type=1) + ventas que NO suman.
 *
 * Tenant: $roc en queries de transactions; companyId bound en contact + company.
 * Fixes PG: EXTRACT(MONTH ...) en vez de MONTH(); sin USE INDEX; sin LIMIT en COUNT.
 */
final class SummaryYearService
{
    public function __construct(private readonly NonAddingSales $nonAdding = new NonAddingSales()) {}

    /** @return array {year, years:[int], months:[{...}]} */
    public function yearly($year, string $roc, string $companyId, ?string $outletId = null): array
    {
        $year   = (int) $year;
        $reader = new RollupReader();

        $salesMap    = $reader->monthlyBuckets($companyId, 'sales',    $year, $outletId);
        $expensesMap = $reader->monthlyBuckets($companyId, 'expenses', $year, $outletId);
        $returnsMap  = $reader->monthlyBuckets($companyId, 'returns',  $year, $outletId);

        $allMonths = array_unique(array_merge(
            array_keys($salesMap),
            array_keys($expensesMap),
            array_keys($returnsMap)
        ));
        sort($allMonths);

        $months = [];
        foreach ($allMonths as $m) {
            $ms = sprintf('%04d-%02d-01 00:00:00', $year, $m);
            $me = date('Y-m-t 23:59:59', strtotime($ms));

            $s = $salesMap[$m]    ?? ['cnt' => 0, 'total' => 0, 'tax' => 0, 'discount' => 0, 'qty' => 0];
            $e = $expensesMap[$m] ?? ['total' => 0];
            $r = $returnsMap[$m]  ?? ['total' => 0];

            $months[] = [
                'month'          => $m,
                'usold'          => (float) ($s['qty']      ?? 0),
                'count'          => (int)   ($s['cnt']      ?? 0),
                'discount'       => (float) ($s['discount'] ?? 0),
                'tax'            => (float) ($s['tax']      ?? 0),
                'salesTotal'     => (float) ($s['total']    ?? 0),
                'expensesTotal'  => (float) ($e['total']    ?? 0),
                'returnsTotal'   => (float) ($r['total']    ?? 0),
                'nonAddingTotal' => $this->nonAddingTotal($ms, $me, $roc),
                'customers'      => $this->newCustomers($ms, $me, $roc),
            ];
        }

        return [
            'year'   => $year,
            'years'  => $this->yearsSince($companyId),
            'months' => $months,
        ];
    }

    public function yearlyLive($year, string $roc, string $companyId): array
    {
        $year      = (int) $year;
        $startYear = sprintf('%04d-01-01 00:00:00', $year);
        $endYear   = ($year < (int) date('Y'))
            ? sprintf('%04d-12-31 23:59:59', $year)
            : date('Y-m-d 23:59:59');

        $res = ncmExecute(
            'SELECT EXTRACT(MONTH FROM transactionDate)::int AS month,
                    COALESCE(SUM(transactionUnitsSold), 0) AS usold,
                    COUNT(*)                               AS count,
                    COALESCE(SUM(transactionDiscount), 0)  AS discount,
                    COALESCE(SUM(transactionTax), 0)       AS tax,
                    COALESCE(SUM(transactionTotal), 0)     AS total
             FROM transaction
             WHERE transactionType IN (0, 3)
               AND transactionDate BETWEEN ? AND ?' . $roc . '
             GROUP BY EXTRACT(MONTH FROM transactionDate)
             ORDER BY month ASC',
            [$startYear, $endYear], false, true
        );

        $months = [];
        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f  = $res->fields;
                $m  = (int) $f['month'];
                $ms = sprintf('%04d-%02d-01 00:00:00', $year, $m);
                $me = date('Y-m-t 23:59:59', strtotime($ms));

                $months[] = [
                    'month'          => $m,
                    'usold'          => (float) ($f['usold'] ?? 0),
                    'count'          => (int)   ($f['count'] ?? 0),
                    'discount'       => (float) ($f['discount'] ?? 0),
                    'tax'            => (float) ($f['tax'] ?? 0),
                    'salesTotal'     => (float) ($f['total'] ?? 0),
                    'expensesTotal'  => $this->expensesTotal($ms, $me, $roc),
                    'returnsTotal'   => $this->returnsTotal($ms, $me, $roc),
                    'nonAddingTotal' => $this->nonAddingTotal($ms, $me, $roc),
                    'customers'      => $this->newCustomers($ms, $me, $roc),
                ];
                $res->MoveNext();
            }
            $res->Close();
        }

        return [
            'year'   => $year,
            'years'  => $this->yearsSince($companyId),
            'months' => $months,
        ];
    }

    private function expensesTotal($from, $to, $roc): float
    {
        $r = ncmExecute(
            'SELECT COALESCE(SUM(transactionTotal), 0) AS total FROM transaction
             WHERE transactionType IN (1, 4) AND transactionDate BETWEEN ? AND ?' . $roc,
            [$from, $to]
        );
        return (float) ($r['total'] ?? 0);
    }

    /** Devoluciones (tipo 6) — magnitud positiva (SUM(ABS)). */
    private function returnsTotal($from, $to, $roc): float
    {
        $r = ncmExecute(
            'SELECT COALESCE(SUM(ABS(transactionTotal)), 0) AS total FROM transaction
             WHERE transactionType IN (6) AND transactionDate BETWEEN ? AND ?' . $roc,
            [$from, $to]
        );
        return (float) ($r['total'] ?? 0);
    }

    /** Ventas que NO suman (gift card / crédito interno / puntos). Vía NonAddingSales helper. */
    private function nonAddingTotal(string $from, string $to, string $roc): float
    {
        $na = $this->nonAdding->compute($from, $to, $roc, false, 1);
        return (float) ($na['total'] ?? 0);
    }

    /**
     * Clientes nuevos (contact type=1) creados en el período. PORT FIEL del legacy: usa el
     * $roc completo (companyId + outletId) → solo cuenta los clientes registrados en la
     * sucursal actual del usuario. Si producto decide "por toda la company", el cambio es
     * cambiar $roc por bound `companyId = ?` acá — deuda registrada como mejora opcional.
     */
    private function newCustomers($from, $to, string $roc): int
    {
        $r = ncmExecute(
            'SELECT COUNT(contactId) AS total FROM contact
             WHERE type = 1 AND contactDate BETWEEN ? AND ?' . $roc,
            [$from, $to]
        );
        return (int) ($r['total'] ?? 0);
    }

    /** Lista de años desde la creación de la company hasta hoy (desc), para el selector. */
    private function yearsSince(string $companyId): array
    {
        $r = ncmExecute('SELECT createdAt FROM company WHERE companyId = ?', [$companyId]);
        $created = ($r && !empty($r['createdAt'])) ? (int) date('Y', strtotime($r['createdAt'])) : (int) date('Y');
        $now     = (int) date('Y');
        if ($created > $now) { $created = $now; }

        $years = [];
        for ($y = $now; $y >= $created; $y--) {
            $years[] = $y;
        }
        return $years;
    }
}
