<?php
/**
 * Dominio de Reportes — Flujo de Caja / Cashflow (capa API, motor ERP).
 *
 * UNA vista de lectura: getCashFlow() — resumen de flujo de caja del período (ingresos por
 * ventas + cobros de deudas; egresos por compra de mercadería + gastos + pagos de deudas;
 * saldo inicial del período anterior; saldo final y acumulado).
 *
 * Read-only. Devuelve datos CRUDOS (números); el front formatea + arma KPIs + tabla. Ver REGLA RAÍZ 2.
 * (El `getChartSales` del legacy era código muerto — su $sql nunca se definía y el front no lo
 *  llamaba — así que NO se migra.)
 *
 * Decisión de semántica (resuelve el wrinkle MySQL→PG): el split mercadería/servicios usaba
 * `itemId > 0` (mercadería) y `itemId = 0` (servicios) — convención MySQL donde 0 = sin ítem.
 * En PG el itemId es UUID (no hay 0): mercadería = `itemId IS NOT NULL`, gasto/servicio =
 * `itemId IS NULL`. Es la traducción natural y correcta de la intención original.
 *
 * Tenant: $roc (getROC) por query; companyId implícito en el roc.
 */
class ReportCashflowService
{
    public function getCashFlow($from, $to)
    {
        $roc = getROC(1);
        [$pFrom, $pTo] = getPreviousPeriod($from, $to);

        $cur  = $this->periodTotals($from, $to, $roc);
        $prev = $this->periodTotals($pFrom, $pTo, $roc);

        $initialCash  = ($prev['cash'] + $prev['payments']) - ($prev['purchase'] + $prev['ppayment'] + $prev['expenses']);
        $incomeTotal  = $cur['payments'] + $cur['cash'];
        $outcomeTotal = $cur['purchase'] + $cur['ppayment'] + $cur['expenses'];
        $remains      = $incomeTotal - $outcomeTotal;

        return [
            'cashSales'        => $cur['cash'],
            'cashPayments'     => $cur['payments'],
            'incomeTotal'      => $incomeTotal,
            'stockPurchase'    => $cur['purchase'],
            'expensesPurchase' => $cur['expenses'],
            'outPayment'       => $cur['ppayment'],
            'outcomeTotal'     => $outcomeTotal,
            'remains'          => $remains,
            'initialCash'      => $initialCash,
            'accumulated'      => $initialCash + $remains,
        ];
    }

    /** Totales de un período: ventas contado, cobros, compra mercadería, pagos compra, gastos. */
    private function periodTotals($from, $to, $roc)
    {
        // Ingresos por ventas contado (+ devoluciones, tipo 0,6): total − descuento.
        $row = ncmExecute(
            "SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount
             FROM transaction WHERE transactionType IN (0,6) AND transactionDate BETWEEN ? AND ?" . $roc,
            [$from, $to]
        );
        // ncmExecute single-row → CaseInsensitiveArray (truthy) o 0/false; acceso por clave defensivo.
        $cash = $row ? ((float) ($row['total'] ?? 0) - (float) ($row['discount'] ?? 0)) : 0.0;

        // Cobros de deudas (pagos tipo 5 cuyo padre es venta a crédito tipo 3).
        $payments = (float) getCashFlowReceivedPayments(5, 3, $roc, $from, $to);
        // Pagos de compras a crédito (pagos tipo 5 cuyo padre es compra a crédito tipo 4).
        $ppayment = (float) getCashFlowReceivedPayments(5, 4, $roc, $from, $to);

        // Egresos — compra de mercadería contado (líneas con ítem real = itemId IS NOT NULL).
        $purchase = $this->purchaseLines($from, $to, $roc, true);
        // Egresos — gastos/servicios contado (líneas sin ítem = itemId IS NULL).
        $expenses = $this->purchaseLines($from, $to, $roc, false);

        return ['cash' => $cash, 'payments' => $payments, 'purchase' => $purchase, 'ppayment' => $ppayment, 'expenses' => $expenses];
    }

    /**
     * Σ itemSoldTotal de compras contado (tipo 1) del período. $merchandise=true → líneas con
     * ítem (mercadería); false → líneas sin ítem (gastos/servicios). Fix PG: itemId es UUID,
     * el legacy usaba `itemId > 0` / `itemId = 0` (semántica MySQL) → IS NOT NULL / IS NULL.
     */
    private function purchaseLines($from, $to, $roc, $merchandise)
    {
        $itemClause = $merchandise ? 'AND b.itemId IS NOT NULL' : 'AND b.itemId IS NULL';
        $rocB = str_replace(
            ['registerId', 'outletId', 'companyId'],
            ['a.registerId', 'a.outletId', 'a.companyId'],
            $roc
        );
        $row = ncmExecute(
            "SELECT SUM(b.itemSoldTotal) as total
             FROM transaction a, itemSold b
             WHERE a.transactionType = 1 AND a.transactionDate BETWEEN ? AND ?" . $rocB . "
             AND a.transactionId = b.transactionId " . $itemClause,
            [$from, $to]
        );
        return $row ? (float) ($row['total'] ?? 0) : 0.0;
    }
}
