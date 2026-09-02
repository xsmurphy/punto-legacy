<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes de Ventas (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportSalesService.php (Fase 2 batch 8). Cambios vs original:
 *  - namespace + `final`
 *  - `getSalesByPayment($from, $to)` (resolvía a la versión de /app — firma 3-arg con
 *    registerId, no aplica al panel) → reemplazado por `NonAddingSales::salesByPayment`
 *    (port estático del panel, batch 7).
 *  - `getNonAddingToSales(['startDate'=>...])` (sólo en panel) → reemplazado por
 *    `NonAddingSales::compute` (helper compartido del batch 7).
 *  - `getPaymentMethodName` ya existe en /app — resuelve por fallback de namespace.
 *  - Todas las queries reciben `$roc` por parámetro (no globals).
 *
 * 4 datasets: summary, series, hours, byday — el front compone netSales/margin/comparaciones
 * en el BFF y formatea + arma chart/tabla en el JS.
 *
 * Tenant: $roc del endpoint (Roc::build); companyId bound en queries que lo requieran.
 */
final class SalesService
{
    public function __construct(private readonly NonAddingSales $nonAdding = new NonAddingSales()) {}

    /** Totales de ventas (tipos 0 = contado, 3 = crédito) en un período. */
    public function salesTotals($from, $to, $roc, HourBand $hours = new HourBand()): array
    {
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $sql = 'SELECT
                    COALESCE(SUM(transactionUnitsSold), 0) AS unitssold,
                    COUNT(transactionDate)                 AS count,
                    COALESCE(SUM(transactionDiscount), 0)  AS discount,
                    COALESCE(SUM(transactionTax), 0)       AS tax,
                    COALESCE(SUM(transactionTotal), 0)     AS total
                FROM transaction
                WHERE transactionType IN (0, 3)
                AND ' . SaleFilters::notVoidedSql() . '
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc . $hourSql;

        $r = ncmExecute($sql, array_merge([$from, $to], $hourParams));

        return [
            'unitsSold' => (float) ($r['unitssold'] ?? 0),
            'count'     => (int)   ($r['count'] ?? 0),
            'discount'  => (float) ($r['discount'] ?? 0),
            'tax'       => (float) ($r['tax'] ?? 0),
            'total'     => (float) ($r['total'] ?? 0),
        ];
    }

    /** Total de devoluciones (tipo 6) en un período. */
    public function returnsTotal($from, $to, $roc, HourBand $hours = new HourBand()): float
    {
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $sql = 'SELECT COALESCE(SUM(transactionTotal), 0) AS returned
                FROM transaction
                WHERE transactionType IN (6)
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc . $hourSql;

        $r = ncmExecute($sql, array_merge([$from, $to], $hourParams));
        return (float) ($r['returned'] ?? 0);
    }

    /** Totales separados por tipo: contado (0) y crédito (3). */
    public function salesByType($from, $to, $roc, HourBand $hours = new HourBand()): array
    {
        // El tipo va PRIMERO en los binds, así que la franja se agrega al final
        // del WHERE y sus params al final del array — no al lado del rango.
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $sql = 'SELECT
                    COALESCE(SUM(transactionDiscount), 0) AS discount,
                    COALESCE(SUM(transactionTotal), 0)    AS total
                FROM transaction
                WHERE transactionType = ?
                AND ' . SaleFilters::notVoidedSql() . '
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc . $hourSql;

        $cash   = ncmExecute($sql, array_merge([0, $from, $to], $hourParams));
        $credit = ncmExecute($sql, array_merge([3, $from, $to], $hourParams));

        return [
            'cash'   => ['total' => (float) ($cash['total'] ?? 0),   'discount' => (float) ($cash['discount'] ?? 0)],
            'credit' => ['total' => (float) ($credit['total'] ?? 0), 'discount' => (float) ($credit['discount'] ?? 0)],
        ];
    }

    /** Gift cards vendidas en un período. */
    public function giftcardsSold($from, $to, $companyId, HourBand $hours = new HourBand()): array
    {
        // La franja de una gift card vendida se mide sobre la LÍNEA
        // (`itemSold`), que es lo que esta query acota — no sobre la cabecera.
        [$hourSql, $hourParams] = $hours->on('b.itemSoldDate');

        $sql = "SELECT
                    COALESCE(SUM(b.itemSoldTotal), 0) AS total,
                    COALESCE(SUM(b.itemSoldUnits), 0) AS count
                FROM item a, itemSold b
                WHERE a.itemType = 'giftcard'
                AND a.itemId = b.itemId
                AND a.companyId = ?
                AND b.itemSoldDate BETWEEN ? AND ?" . $hourSql;

        $r = ncmExecute($sql, array_merge([$companyId, $from, $to], $hourParams));

        return [
            'total' => (float) ($r['total'] ?? 0),
            'count' => (float) ($r['count'] ?? 0),
        ];
    }

    /**
     * Dataset crudo del resumen de ventas de UN período.
     * El BFF llama esto una vez por período (actual + anterior) y compone/formatea.
     */
    public function summary($from, $to, $roc, $companyId, HourBand $hours = new HourBand()): array
    {
        $payments = [];
        foreach (NonAddingSales::salesByPayment($from, $to, $roc, 0, $hours) as $m) {
            $payments[] = [
                'type'  => $m['type'],
                'name'  => getPaymentMethodName($m['type']),
                'price' => (float) ($m['price'] ?? 0),
                'total' => (float) ($m['total'] ?? 0),
            ];
        }

        $nonAdding = $this->nonAdding->compute($from, $to, $roc, false, 0, $hours);

        return [
            'totals'    => $this->salesTotals($from, $to, $roc, $hours),
            'returns'   => ['total' => $this->returnsTotal($from, $to, $roc, $hours)],
            'byType'    => $this->salesByType($from, $to, $roc, $hours),
            'giftcards' => $this->giftcardsSold($from, $to, $companyId, $hours),
            'payments'  => $payments,
            'nonAddingToSales' => [
                'total'          => (float) ($nonAdding['total'] ?? 0),
                'totalGiftCards' => (float) ($nonAdding['totalGiftCards'] ?? 0),
            ],
        ];
    }

    /**
     * Series crudas de UN período para el gráfico. Si el rango es de un solo día, agrupa
     * por hora; si abarca varios, agrupa por fecha. Devuelve ventas (0,3,6) y egresos (1,4).
     */
    public function series($from, $to, $roc, $isDay, HourBand $hours = new HourBand()): array
    {
        // Las dos queries (ventas y egresos) filtran la MISMA columna, así que
        // comparten fragmento y params — pero cada una los bindea por su cuenta.
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $bucket = $isDay
            ? 'EXTRACT(HOUR FROM transactionDate)::int'
            : 'transactionDate::date';

        $salesSql = 'SELECT ' . $bucket . ' AS bucket,
                        COUNT(transactionId)                   AS count,
                        COALESCE(SUM(transactionUnitsSold), 0) AS units,
                        COALESCE(SUM(transactionDiscount), 0)  AS discount,
                        COALESCE(SUM(transactionTax), 0)       AS tax,
                        COALESCE(SUM(transactionTotal), 0)     AS total
                     FROM transaction
                     WHERE transactionType IN (0, 3, 6)
                     AND ' . SaleFilters::notVoidedSql() . '
                     AND transactionDate >= ?
                     AND transactionDate <= ?' . $roc . $hourSql . '
                     GROUP BY bucket
                     ORDER BY bucket ASC';

        $expSql = 'SELECT ' . $bucket . ' AS bucket,
                      COALESCE(SUM(transactionTotal), 0)    AS total,
                      COALESCE(SUM(transactionDiscount), 0) AS discount
                   FROM transaction
                   WHERE transactionType IN (1, 4)
                   AND transactionStatus <> 6
                   AND transactionDate >= ?
                   AND transactionDate <= ?' . $roc . $hourSql . '
                   GROUP BY bucket
                   ORDER BY bucket ASC';

        return [
            'isDay'    => (bool) $isDay,
            'sales'    => $this->rows($salesSql, array_merge([$from, $to], $hourParams), $isDay),
            'expenses' => $this->rows($expSql, array_merge([$from, $to], $hourParams), $isDay),
        ];
    }

    /**
     * Conteo de ventas por hora del día (tipos 0,3) — para el gráfico "Ventas por Hora".
     *
     * La columna del bucket se alias`ea `bucket` como el resto de los datasets:
     * `rows()` lee `$f['bucket']` y nada más. Con el alias viejo (`hour`) el
     * campo no existía, `(int) null` daba 0 y las 24 horas colapsaban en la
     * hora 0 — el gráfico mostraba todo el período apilado en "00h" y el resto
     * en cero. Toda query nueva de este servicio nombra su bucket `bucket`.
     */
    public function hours($from, $to, $roc, HourBand $hours = new HourBand()): array
    {
        // Este dataset YA agrupa por hora; la franja acota qué barras salen.
        // No se solapan: una es el eje del gráfico, la otra el filtro.
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $sql = 'SELECT EXTRACT(HOUR FROM transactionDate)::int AS bucket,
                    COUNT(transactionId)                   AS count,
                    COALESCE(SUM(transactionUnitsSold), 0) AS units,
                    COALESCE(SUM(transactionTotal), 0)     AS total
                FROM transaction
                WHERE transactionType IN (0, 3)
                AND ' . SaleFilters::notVoidedSql() . '
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc . $hourSql . '
                GROUP BY bucket
                ORDER BY bucket ASC';

        return $this->rows($sql, array_merge([$from, $to], $hourParams), true);
    }

    /** Filas crudas por día (tipos 0,3,6) para la pestaña "Por Día". */
    public function byDay($from, $to, $roc, HourBand $hours = new HourBand()): array
    {
        [$hourSql, $hourParams] = $hours->on('transactionDate');

        $sql = 'SELECT transactionDate::date            AS bucket,
                    COALESCE(SUM(transactionUnitsSold), 0) AS units,
                    COUNT(transactionDate)                 AS count,
                    COALESCE(SUM(transactionDiscount), 0)  AS discount,
                    COALESCE(SUM(transactionTax), 0)       AS tax,
                    COALESCE(SUM(transactionTotal), 0)     AS total
                FROM transaction
                WHERE transactionType IN (0, 3, 6)
                AND ' . SaleFilters::notVoidedSql() . '
                AND transactionDate >= ?
                AND transactionDate <= ?' . $roc . $hourSql . '
                GROUP BY transactionDate::date
                ORDER BY bucket DESC';

        $rows = [];
        foreach ($this->rows($sql, array_merge([$from, $to], $hourParams), false) as $r) {
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
    private function rows($sql, array $params, $isDay): array
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
