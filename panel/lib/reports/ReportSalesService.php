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

        $r = ncmExecute($sql, [$from, $to]);

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

        $r = ncmExecute($sql, [$from, $to]);

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

        $cash   = ncmExecute($sql, [0, $from, $to]);
        $credit = ncmExecute($sql, [3, $from, $to]);

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

        $r = ncmExecute($sql, [$companyId, $from, $to]);

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
                // El nombre del medio (incl. métodos custom de la company) sale de la BD
                // → es dato del ERP, no presentación; lo resuelve la API. El front formatea el precio.
                'name'  => getPaymentMethodName($m['type']),
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

    /**
     * Series crudas de UN período para el gráfico. El BFF llama esto una vez por
     * período (actual + anterior) y compone labels/margin/byweek/anotaciones.
     *
     * Si el rango es de un solo día, agrupa por hora (bucket 0..23); si abarca
     * varios días, agrupa por fecha (bucket 'Y-m-d'). Devuelve ventas (tipos
     * 0,3,6) y egresos (tipos 1,4) — números crudos, sin formatear ni rellenar.
     */
    public function series($from, $to, $roc, $isDay)
    {
        if ($isDay) {
            // Por hora del día (EXTRACT, no el HOUR() de MySQL).
            $bucket = 'EXTRACT(HOUR FROM transactionDate)::int';
        } else {
            // Por fecha (cast ::date, no el DATE() de MySQL).
            $bucket = 'transactionDate::date';
        }

        $salesSql = 'SELECT ' . $bucket . ' AS bucket,
                        COUNT(transactionId)                   AS count,
                        COALESCE(SUM(transactionUnitsSold), 0) AS units,
                        COALESCE(SUM(transactionDiscount), 0)  AS discount,
                        COALESCE(SUM(transactionTax), 0)       AS tax,
                        COALESCE(SUM(transactionTotal), 0)     AS total
                     FROM transaction
                     WHERE transactionType IN (0, 3, 6)
                     AND transactionDate >= ?
                     AND transactionDate <= ?' . $roc . '
                     GROUP BY bucket
                     ORDER BY bucket ASC';

        $expSql = 'SELECT ' . $bucket . ' AS bucket,
                      COALESCE(SUM(transactionTotal), 0)    AS total,
                      COALESCE(SUM(transactionDiscount), 0) AS discount
                   FROM transaction
                   WHERE transactionType IN (1, 4)
                   AND transactionDate >= ?
                   AND transactionDate <= ?' . $roc . '
                   GROUP BY bucket
                   ORDER BY bucket ASC';

        return [
            'isDay'    => (bool) $isDay,
            'sales'    => $this->rows($salesSql, [$from, $to], $isDay),
            'expenses' => $this->rows($expSql, [$from, $to], $isDay),
        ];
    }

    /** Conteo de ventas por hora del día (tipos 0,3) — para el gráfico "Ventas por Hora". */
    public function hours($from, $to, $roc)
    {
        $sql = 'SELECT EXTRACT(HOUR FROM transactionDate)::int AS hour,
                    COUNT(transactionId)                   AS total,
                    COALESCE(SUM(transactionUnitsSold), 0) AS units
                FROM transaction
                WHERE transactionType IN (0, 3)
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc . '
                GROUP BY hour
                ORDER BY hour ASC';

        return $this->rows($sql, [$from, $to], true);
    }

    /**
     * Filas crudas por día (tipos 0,3,6) para la pestaña "Por Día".
     * La resta de ventas internas (lessInternalTotals) está desactivada salvo que
     * la company tenga ignoreInternal — se deja al front/BFF cuando aplique.
     */
    public function byDay($from, $to, $roc)
    {
        $sql = 'SELECT transactionDate::date            AS bucket,
                    COALESCE(SUM(transactionUnitsSold), 0) AS units,
                    COUNT(transactionDate)                 AS count,
                    COALESCE(SUM(transactionDiscount), 0)  AS discount,
                    COALESCE(SUM(transactionTax), 0)       AS tax,
                    COALESCE(SUM(transactionTotal), 0)     AS total
                FROM transaction
                WHERE transactionType IN (0, 3, 6)
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc . '
                GROUP BY transactionDate::date
                ORDER BY bucket DESC';

        $rows = [];
        foreach ($this->rows($sql, [$from, $to], false) as $r) {
            $rows[] = [
                'date'     => (string) $r['bucket'],
                'usold'    => (float) $r['units'],
                'count'    => (int)   $r['count'],
                'discount' => (float) $r['discount'],
                'tax'      => (float) $r['tax'],
                'total'    => (float) $r['total'],
            ];
        }
        return $rows;
    }

    /** Ejecuta una query multi-fila y la colecta en un array de filas crudas. */
    private function rows($sql, array $params, $isDay)
    {
        $res  = ncmExecute($sql, $params, false, true);
        $rows = [];

        if ($res && is_object($res)) {
            while (!$res->EOF) {
                $f      = $res->fields;
                $bucket = $isDay ? (int) $f['bucket'] : (string) $f['bucket'];
                $rows[] = [
                    'bucket'   => $bucket,
                    'count'    => isset($f['count'])    ? (int) $f['count']      : 0,
                    'units'    => isset($f['units'])    ? (float) $f['units']    : 0,
                    'discount' => isset($f['discount']) ? (float) $f['discount'] : 0,
                    'tax'      => isset($f['tax'])      ? (float) $f['tax']      : 0,
                    'total'    => isset($f['total'])    ? (float) $f['total']    : 0,
                ];
                $res->MoveNext();
            }
        }

        return $rows;
    }
}
