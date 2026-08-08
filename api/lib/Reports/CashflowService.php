<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Flujo de Caja (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportCashflowService.php (Fase 2 batch 4). Cambios vs original:
 *  - namespace + `final`
 *  - el ROC se recibe por PARÁMETRO (el original llamaba `getROC(1)` dentro del service, que no
 *    existe en /api). El endpoint compone el ROC con `Roc::build()` y lo pasa.
 *  - `getPreviousPeriod` y `getCashFlowReceivedPayments` (sólo viven en panel) → portados como
 *    métodos privados (port fiel; semántica MySQL→PG preservada: `itemId IS NOT NULL/NULL`).
 *  - `getCashFlowReceivedPayments` ahora recibe `$companyId` por parámetro (mejor higiene
 *    multi-tenant; el original leía COMPANY_ID de la constante global).
 *
 * Read-only. Sin formatear: el front formatea + arma KPIs + tabla.
 */
final class CashflowService
{
    public function getCashFlow($from, $to, string $roc, string $companyId)
    {
        [$pFrom, $pTo] = $this->previousPeriod($from, $to);

        $cur  = $this->periodTotals($from, $to, $roc, $companyId);
        $prev = $this->periodTotals($pFrom, $pTo, $roc, $companyId);

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
    private function periodTotals($from, $to, $roc, $companyId)
    {
        $row = ncmExecute(
            "SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount
             FROM transaction WHERE transactionType IN (0,6) AND transactionDate BETWEEN ? AND ?" . $roc,
            [$from, $to]
        );
        $cash = $row ? ((float) ($row['total'] ?? 0) - (float) ($row['discount'] ?? 0)) : 0.0;

        $payments = (float) $this->receivedPayments(5, 3, $roc, $from, $to, $companyId);
        $ppayment = (float) $this->receivedPayments(5, 4, $roc, $from, $to, $companyId);

        $purchase = $this->purchaseLines($from, $to, $roc, true);
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

    /**
     * Port fiel de getCashFlowReceivedPayments (panel-only). $companyId explícito.
     * Suma transactionTotal de los pagos (typePay) cuyo padre es del tipo $typeTrans.
     */
    private function receivedPayments($typePay, $typeTrans, $roc, $from, $to, $companyId): float
    {
        // Sólo se leen transactionId (batch de origen vía transaction_link) y
        // transactionTotal (suma) — ninguna vive en meta/data/config.
        $sql = "SELECT transactionId, transactionTotal FROM transaction
                WHERE transactionType IN (" . (int) $typePay . ")
                AND transactionDate BETWEEN ? AND ? " . $roc . "
                ORDER BY transactionDate DESC";
        $result = ncmExecute($sql, [$from, $to], false, true, true);
        $sum = 0.0;
        if ($result) {
            // mig 115: transactionParentId dropeada — batch lookup del origen
            // vía transaction_link (TransactionLinkService), sin N+1.
            $rows = is_array($result) ? $result : [];
            $derivedIds = array_map(static fn($f) => (string) $f['transactionId'], $rows);
            $originByDerived = $derivedIds !== []
                ? (new \Punto\Api\Services\TransactionLinkService())->mapOriginIdByDerivedIds($companyId, $derivedIds, 'credit_payment')
                : [];

            $originIds = array_values(array_unique(array_filter($originByDerived)));
            $matchOrigins = [];
            if ($originIds !== []) {
                $ph = implode(',', array_fill(0, count($originIds), '?'));
                $rs = ncmExecute(
                    "SELECT transactionId FROM transaction WHERE transactionId IN ($ph) AND transactionType = ? AND companyId = ?",
                    array_merge($originIds, [$typeTrans, $companyId]),
                    false,
                    true
                );
                if ($rs) {
                    while (!$rs->EOF) {
                        $matchOrigins[(string) $rs->fields['transactionId']] = true;
                        $rs->MoveNext();
                    }
                }
            }

            foreach ($rows as $fields) {
                $origin = $originByDerived[(string) $fields['transactionId']] ?? null;
                if ($origin !== null && isset($matchOrigins[$origin])) {
                    $sum += (float) $fields['transactionTotal'];
                }
            }
        }
        return $sum;
    }

    /** Port fiel de getPreviousPeriod (panel-only): mismo intervalo desplazado hacia atrás. */
    private function previousPeriod($start, $end): array
    {
        $startF    = strtotime($start);
        $endF      = strtotime($end);
        $diference = ($endF - $startF) + 1;
        return [
            date('Y-m-d H:i:00', ($startF - $diference)),
            date('Y-m-d H:i:00', ($endF   - $diference)),
        ];
    }
}
