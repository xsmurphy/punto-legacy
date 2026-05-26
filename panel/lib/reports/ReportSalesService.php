<?php
/**
 * Dominio de Reportes de Ventas — capa API (motor ERP, raw).
 *
 * Devuelve datasets CRUDOS (números, sin formatear, sin HTML). El formateo,
 * las comparaciones de período y la composición para la App Punto viven en el
 * BFF (panel/bff/reports/*.php). Ver context/02-arquitectura.md § BFF de 3 niveles.
 *
 * La SQL de agregación que antes vivía inline en panel/a_report_summary.php
 * (handlers action=getSales/getTypeSales/getGiftcards) se consolida acá.
 *
 * Tenant: el filtro companyId/outlet/register llega como fragmento $roc
 * (getROC()), derivado de COMPANY_ID del JWT — nunca de input del usuario.
 */
class ReportSalesService
{
    /** Totales de ventas (tipos 0 = contado, 3 = crédito) en un período. */
    public function salesTotals($from, $to, $roc)
    {
        $sql = 'SELECT
                    COALESCE(SUM(transactionUnitsSold), 0) AS unitssold,
                    COUNT(transactionDate)                 AS count,
                    COALESCE(SUM(transactionDiscount), 0)  AS discount,
                    COALESCE(SUM(transactionTax), 0)       AS tax,
                    COALESCE(SUM(transactionTotal), 0)     AS total
                FROM transaction
                WHERE transactionType IN (0, 3)
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc;

        $r = ncmExecute($sql, [$from, $to], true);

        return [
            'unitsSold' => (float) ($r['unitssold'] ?? 0),
            'count'     => (int)   ($r['count'] ?? 0),
            'discount'  => (float) ($r['discount'] ?? 0),
            'tax'       => (float) ($r['tax'] ?? 0),
            'total'     => (float) ($r['total'] ?? 0),
        ];
    }

    /** Total de devoluciones (tipo 6) en un período. Devuelve el total crudo (negativo en BD). */
    public function returnsTotal($from, $to, $roc)
    {
        $sql = 'SELECT COALESCE(SUM(transactionTotal), 0) AS returned
                FROM transaction
                WHERE transactionType IN (6)
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc;

        $r = ncmExecute($sql, [$from, $to], true);

        return (float) ($r['returned'] ?? 0);
    }

    /** Totales separados por tipo: contado (0) y crédito (3). */
    public function salesByType($from, $to, $roc)
    {
        $sql = 'SELECT
                    COALESCE(SUM(transactionDiscount), 0) AS discount,
                    COALESCE(SUM(transactionTotal), 0)    AS total
                FROM transaction
                WHERE transactionType = ?
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc;

        $cash   = ncmExecute($sql, [0, $from, $to], true);
        $credit = ncmExecute($sql, [3, $from, $to], true);

        return [
            'cash' => [
                'total'    => (float) ($cash['total'] ?? 0),
                'discount' => (float) ($cash['discount'] ?? 0),
            ],
            'credit' => [
                'total'    => (float) ($credit['total'] ?? 0),
                'discount' => (float) ($credit['discount'] ?? 0),
            ],
        ];
    }

    /** Gift cards vendidas en un período (monto y unidades). */
    public function giftcardsSold($from, $to, $companyId)
    {
        $sql = 'SELECT
                    COALESCE(SUM(b.itemSoldTotal), 0) AS total,
                    COALESCE(SUM(b.itemSoldUnits), 0) AS count
                FROM item a, itemSold b
                WHERE a.itemType = \'giftcard\'
                AND a.itemId = b.itemId
                AND a.companyId = ?
                AND b.itemSoldDate BETWEEN ? AND ?';

        $r = ncmExecute($sql, [$companyId, $from, $to], true);

        return [
            'total' => (float) ($r['total'] ?? 0),
            'count' => (float) ($r['count'] ?? 0),
        ];
    }

    /**
     * Dataset crudo del resumen de ventas de UN período.
     * El BFF llama esto una vez por período (actual + anterior) y compone/formatea.
     */
    public function summary($from, $to, $roc, $companyId)
    {
        // getSalesByPayment se auto-scopea por tenant (llama getROC con los globals
        // OUTLET_ID/COMPANY_ID del JWT); no recibe el fragmento $roc.
        $payments = [];
        foreach (getSalesByPayment($from, $to) as $m) {
            $payments[] = [
                'type'  => $m['type'],
                'price' => (float) ($m['price'] ?? 0),
                'total' => (float) ($m['total'] ?? 0),
            ];
        }

        $nonAdding = getNonAddingToSales([
            'startDate' => $from,
            'endDate'   => $to,
            'roc'       => $roc,
            'backThen'  => false,
        ]);

        return [
            'totals'    => $this->salesTotals($from, $to, $roc),
            'returns'   => ['total' => $this->returnsTotal($from, $to, $roc)],
            'byType'    => $this->salesByType($from, $to, $roc),
            'giftcards' => $this->giftcardsSold($from, $to, $companyId),
            'payments'  => $payments,
            'nonAddingToSales' => [
                'total'          => (float) ($nonAdding['total'] ?? 0),
                'totalGiftCards' => (float) ($nonAdding['totalGiftCards'] ?? 0),
            ],
        ];
    }
}
