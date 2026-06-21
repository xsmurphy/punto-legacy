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
}
