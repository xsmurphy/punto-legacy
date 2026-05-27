<?php
/**
 * Dominio de Reportes — Resumen Anual de Ingresos y Egresos (capa API, motor ERP).
 *
 * Por cada mes con ventas en el año: agregados de ventas (tipos 0,3) + gastos (1,4) +
 * devoluciones (6, magnitud) + clientes nuevos (contact type=1) + ventas que NO suman
 * (gift card / crédito interno / puntos, vía getNonAddingToSales). Filas CRUDAS (números),
 * sin formatear, sin HTML. El BFF deriva netTotal/revenue/margen + promedio; el front
 * formatea, mapea mes→nombre y arma tabla + chart. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_summary_year.php (action=generalTable).
 *
 * Fixes PG vs legacy: `MONTH(transactionDate)` → `EXTRACT(MONTH FROM transactionDate)::int`;
 * sin `USE INDEX` (idiom MySQL); se elimina el `transactionDate as date` no agrupado (PG rechaza
 * columnas fuera del GROUP BY) — las fronteras del mes se derivan del año+mes en PHP. El `LIMIT
 * max_customers` sobre un COUNT (no-op) se omite.
 *
 * Tenant: $roc (getROC) en las queries de transacciones/contactos; companyId para createdAt.
 */
class ReportSummaryYearService
{
    /** @return array {year, years:[int], months:[{month,usold,count,discount,tax,salesTotal,expensesTotal,returnsTotal,nonAddingTotal,customers}]} */
    public function yearly($year, $roc, $companyId)
    {
        $year      = (int) $year;
        $startYear = sprintf('%04d-01-01 00:00:00', $year);
        // Año en curso: hasta hoy; años pasados: hasta fin de año.
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
                    'returnsTotal'   => $this->returnsTotal($ms, $me, $roc),  // magnitud (positiva)
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

    /** Gastos (tipos 1=gasto, 4=retiro) en el período. */
    private function expensesTotal($from, $to, $roc)
    {
        $r = ncmExecute(
            'SELECT COALESCE(SUM(transactionTotal), 0) AS total FROM transaction
             WHERE transactionType IN (1, 4) AND transactionDate BETWEEN ? AND ?' . $roc,
            [$from, $to]
        );
        return (float) ($r['total'] ?? 0);
    }

    /** Devoluciones (tipo 6) — magnitud positiva (SUM(ABS)). */
    private function returnsTotal($from, $to, $roc)
    {
        $r = ncmExecute(
            'SELECT COALESCE(SUM(ABS(transactionTotal)), 0) AS total FROM transaction
             WHERE transactionType IN (6) AND transactionDate BETWEEN ? AND ?' . $roc,
            [$from, $to]
        );
        return (float) ($r['total'] ?? 0);
    }

    /** Ventas que NO suman al total (gift card / crédito interno / puntos). */
    private function nonAddingTotal($from, $to, $roc)
    {
        $na = getNonAddingToSales([
            'startDate' => $from,
            'endDate'   => $to,
            'roc'       => $roc,
            'backThen'  => false,
            'cache'     => true,
        ]);
        return (float) ($na['total'] ?? 0);
    }

    /** Clientes nuevos (contact type=1) creados en el período. */
    private function newCustomers($from, $to, $roc)
    {
        $r = ncmExecute(
            'SELECT COUNT(contactId) AS total FROM contact
             WHERE type = 1 AND contactDate BETWEEN ? AND ?' . $roc,
            [$from, $to]
        );
        return (int) ($r['total'] ?? 0);
    }

    /** Lista de años desde la creación de la company hasta hoy (desc), para el selector. */
    private function yearsSince($companyId)
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
