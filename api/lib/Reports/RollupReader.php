<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * RollupReader — lectura de report_rollup para reportes.
 *
 * monthlyBuckets: dado un año y dominio, devuelve un map mes→métricas
 * leyendo los buckets periodType='month' del rollup.
 * outletId vacío o null → filas consolidadas (outletId IS NULL en el rollup).
 */
final class RollupReader
{
    /**
     * @return array<int, array{cnt:int,total:float,tax:float,discount:float,qty:float}>
     *         map month(1-12) => métricas
     */
    public function monthlyBuckets(string $companyId, string $domain, int $year, ?string $outletId): array
    {
        $from = sprintf('%04d-01-01', $year);
        $to   = sprintf('%04d-12-31', $year);

        if ($outletId !== null && $outletId !== '') {
            $rs = ncmExecute(
                "SELECT EXTRACT(MONTH FROM periodStart)::int AS month,
                        cnt, total, tax, discount, qty
                 FROM report_rollup
                 WHERE companyId = ?
                   AND domain = ?
                   AND periodType = 'month'
                   AND periodStart BETWEEN ?::date AND ?::date
                   AND outletId = ?",
                [$companyId, $domain, $from, $to, $outletId],
                false,
                true
            );
        } else {
            $rs = ncmExecute(
                "SELECT EXTRACT(MONTH FROM periodStart)::int AS month,
                        cnt, total, tax, discount, qty
                 FROM report_rollup
                 WHERE companyId = ?
                   AND domain = ?
                   AND periodType = 'month'
                   AND periodStart BETWEEN ?::date AND ?::date
                   AND outletId IS NULL",
                [$companyId, $domain, $from, $to],
                false,
                true
            );
        }

        $map = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f   = $rs->fields;
                $mon = (int) $f['month'];
                $map[$mon] = [
                    'cnt'      => (int)   ($f['cnt']      ?? 0),
                    'total'    => (float) ($f['total']    ?? 0),
                    'tax'      => (float) ($f['tax']      ?? 0),
                    'discount' => (float) ($f['discount'] ?? 0),
                    'qty'      => (float) ($f['qty']      ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $map;
    }

    public function itemSalesRange(string $companyId, string $from, string $to, ?string $outletId): array
    {
        $fromDate = substr($from, 0, 10);
        $toDate   = substr($to,   0, 10);

        if ($outletId !== null && $outletId !== '') {
            $rs = ncmExecute(
                "SELECT entityid::text AS itemid,
                        SUM(qty)      AS qty,
                        SUM(total)    AS total,
                        SUM(tax)      AS tax,
                        SUM(cogs)     AS cogs,
                        SUM(discount) AS discount,
                        SUM((extra->>'comission')::numeric)    AS comission,
                        SUM((extra->>'cogsAbsFlat')::numeric)  AS cogsabsflat,
                        SUM((extra->>'discountFlat')::numeric) AS discountflat
                 FROM report_rollup
                 WHERE companyid = ?
                   AND domain = 'item_sales'
                   AND periodtype = 'day'
                   AND periodstart BETWEEN ?::date AND ?::date
                   AND outletid = ?
                   AND entitykind = 'item'
                 GROUP BY entityid",
                [$companyId, $fromDate, $toDate, $outletId],
                false,
                true
            );
        } else {
            $rs = ncmExecute(
                "SELECT entityid::text AS itemid,
                        SUM(qty)      AS qty,
                        SUM(total)    AS total,
                        SUM(tax)      AS tax,
                        SUM(cogs)     AS cogs,
                        SUM(discount) AS discount,
                        SUM((extra->>'comission')::numeric)    AS comission,
                        SUM((extra->>'cogsAbsFlat')::numeric)  AS cogsabsflat,
                        SUM((extra->>'discountFlat')::numeric) AS discountflat
                 FROM report_rollup
                 WHERE companyid = ?
                   AND domain = 'item_sales'
                   AND periodtype = 'day'
                   AND periodstart BETWEEN ?::date AND ?::date
                   AND outletid IS NULL
                   AND entitykind = 'item'
                 GROUP BY entityid",
                [$companyId, $fromDate, $toDate],
                false,
                true
            );
        }

        $map = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $map[(string) $f['itemid']] = [
                    'qty'          => (float) ($f['qty']          ?? 0),
                    'total'        => (float) ($f['total']        ?? 0),
                    'tax'          => (float) ($f['tax']          ?? 0),
                    'cogs'         => (float) ($f['cogs']         ?? 0),
                    'discount'     => (float) ($f['discount']     ?? 0),
                    'comission'    => (float) ($f['comission']    ?? 0),
                    'cogsAbsFlat'  => (float) ($f['cogsabsflat']  ?? 0),
                    'discountFlat' => (float) ($f['discountflat'] ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $map;
    }

    public function paymentsRange(string $companyId, string $from, string $to, ?string $outletId): array
    {
        $fromDate = substr($from, 0, 10);
        $toDate   = substr($to,   0, 10);

        if ($outletId !== null && $outletId !== '') {
            $rs = ncmExecute(
                "SELECT MIN(extra->>'label') AS label,
                        SUM(total)           AS total,
                        SUM((extra->>'price')::numeric) AS price,
                        SUM(cnt)             AS cnt
                 FROM report_rollup
                 WHERE companyid = ?
                   AND domain = 'payments'
                   AND periodtype = 'day'
                   AND periodstart BETWEEN ?::date AND ?::date
                   AND outletid = ?
                   AND entitykind = 'paymentType'
                 GROUP BY entityid",
                [$companyId, $fromDate, $toDate, $outletId],
                false,
                true
            );
        } else {
            $rs = ncmExecute(
                "SELECT MIN(extra->>'label') AS label,
                        SUM(total)           AS total,
                        SUM((extra->>'price')::numeric) AS price,
                        SUM(cnt)             AS cnt
                 FROM report_rollup
                 WHERE companyid = ?
                   AND domain = 'payments'
                   AND periodtype = 'day'
                   AND periodstart BETWEEN ?::date AND ?::date
                   AND outletid IS NULL
                   AND entitykind = 'paymentType'
                 GROUP BY entityid",
                [$companyId, $fromDate, $toDate],
                false,
                true
            );
        }

        $rows = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $rows[] = [
                    'type'  => (string) ($f['label'] ?? ''),
                    'total' => (float)  ($f['total'] ?? 0),
                    'price' => (float)  ($f['price'] ?? 0),
                    'cnt'   => (int)    ($f['cnt']   ?? 0),
                ];
                $rs->MoveNext();
            }
            $rs->Close();
        }

        usort($rows, fn($a, $b) => $b['price'] <=> $a['price']);

        return $rows;
    }
}
