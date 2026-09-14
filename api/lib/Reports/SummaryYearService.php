<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\Api\Support\TenantClock;

/**
 * Annual document report, retaining the summary_year wire contract.
 * salesTotal retains the stored subtotal; the existing presentation derives
 * income = salesTotal - discount - returnsTotal (returnsTotal retains ABS total).
 * expensesTotal is purchases (1/4), NOT cash movements/payments or finance expenses.
 * nonAddingTotal retains the legacy payment/internal-sale metric: it is not an
 * accrual adjustment. customers retains registrations, not purchasing customers.
 */
final class SummaryYearService
{
    public function __construct(private readonly NonAddingSales $nonAdding = new NonAddingSales()) {}

    /** Null/empty defaults to the tenant year; malformed and out-of-range input fails. */
    public static function parseYear(mixed $year, int $currentYear): int
    {
        if ($year === null || $year === '') {
            return $currentYear;
        }
        if ((!is_string($year) && !is_int($year))
            || !preg_match('/^[0-9]{4}$/D', (string) $year)
            || (int) $year < 1900) {
            throw new \InvalidArgumentException('Año inválido (1900–9999)');
        }
        return (int) $year;
    }

    public function yearly($year, string $roc, string $companyId, array $outletIds = [], bool $forceRollup = false): array
    {
        return $this->build($year, $roc, $companyId,
            $forceRollup || !empty($_ENV['REPORTS_ROLLUP_ENABLED']), $outletIds);
    }

    public function yearlyLive($year, string $roc, string $companyId): array
    {
        return $this->build($year, $roc, $companyId, false, []);
    }

    private function build($year, string $roc, string $companyId, bool $rollup, array $outletIds): array
    {
        TenantClock::apply($companyId);
        $currentYear = (int) substr(TenantClock::now($companyId), 0, 4);
        $year = self::parseYear($year, $currentYear);
        $from = sprintf('%04d-01-01 00:00:00', $year);
        // Half-open PG interval includes subsecond timestamps and year 9999
        // without passing a five-digit year through PHP's date parser.
        $range = "transactionDate >= ?::timestamptz AND transactionDate < (?::timestamptz + interval '1 year')";
        $types = $rollup ? '1,4,6' : '0,1,3,4,6';
        $rows = ncmRows(
            "SELECT EXTRACT(MONTH FROM transactionDate)::int AS month,
                COUNT(*) FILTER (WHERE transactionType IN (0,3)) AS count,
                COALESCE(SUM(transactionUnitsSold) FILTER (WHERE transactionType IN (0,3)),0) AS usold,
                COALESCE(SUM(transactionDiscount) FILTER (WHERE transactionType IN (0,3)),0) AS discount,
                COALESCE(SUM(transactionTax) FILTER (WHERE transactionType IN (0,3)),0) AS tax,
                COALESCE(SUM(transactionTotal) FILTER (WHERE transactionType IN (0,3)),0) AS sales,
                COALESCE(SUM(transactionTotal) FILTER (WHERE transactionType IN (1,4)),0) AS purchases,
                COALESCE(SUM(ABS(transactionTotal)) FILTER (WHERE transactionType = 6),0) AS returns
             FROM transaction WHERE {$range} AND transactionType IN ({$types})
                AND " . self::validDocumentsSql() . $roc . ' GROUP BY month', [$from, $from]
        );
        $documents = array_column(array_map('ncmRow', $rows), null, 'month');
        $sales = $rollup ? (new RollupReader())->monthlyBuckets($companyId, 'sales', $year, $outletIds) : [];
        // report_rollup(expenses) has no cancellation dimension; aggregating
        // authoritative documents avoids serving cancelled purchases in rollup mode.

        $customers = array_column(array_map('ncmRow', ncmRows(
            "SELECT EXTRACT(MONTH FROM contactDate)::int AS month, COUNT(*) AS count
             FROM contact WHERE type = 1 AND contactDate >= ?::timestamptz
                AND contactDate < (?::timestamptz + interval '1 year')" . $roc . ' GROUP BY month',
            [$from, $from]
        )), 'count', 'month');
        $months = [];
        foreach (range(1, 12) as $m) {
            $monthStart = sprintf('%04d-%02d-01 00:00:00', $year, $m);
            $monthEnd = (new \DateTimeImmutable($monthStart))->format('Y-m-t') . ' 23:59:59';
            // Keep the canonical payment/internal-sale calculation shared with other reports.
            $nonAdding = $this->nonAdding->compute($monthStart, $monthEnd, $roc, false, 1);
            $d = $documents[$m] ?? [];
            $s = $sales[$m] ?? [];
            $months[] = [
                'month' => $m,
                'usold' => (float) ($rollup ? ($s['qty'] ?? 0) : ($d['usold'] ?? 0)),
                'count' => (int) ($rollup ? ($s['cnt'] ?? 0) : ($d['count'] ?? 0)),
                'discount' => (float) ($rollup ? ($s['discount'] ?? 0) : ($d['discount'] ?? 0)),
                'tax' => (float) ($rollup ? ($s['tax'] ?? 0) : ($d['tax'] ?? 0)),
                'salesTotal' => (float) ($rollup ? ($s['total'] ?? 0) : ($d['sales'] ?? 0)),
                'expensesTotal' => (float) ($d['purchases'] ?? 0),
                'returnsTotal' => (float) ($d['returns'] ?? 0),
                'nonAddingTotal' => (float) ($nonAdding['total'] ?? 0),
                'customers' => (int) ($customers[$m] ?? 0),
            ];
        }
        $years = array_map('intval', array_column(array_map('ncmRow', ncmRows(
            "SELECT DISTINCT EXTRACT(YEAR FROM transactionDate)::int AS year
             FROM transaction WHERE transactionType IN (0,1,3,4,6)
                AND transactionDate >= '1900-01-01'::timestamptz
                AND transactionDate < '10000-01-01'::timestamptz
                AND " . self::validDocumentsSql() . $roc, []
        )), 'year'));
        $years[] = $currentYear;
        $years = array_values(array_unique($years));
        rsort($years, SORT_NUMERIC);
        return ['year' => $year, 'currentYear' => $currentYear, 'years' => $years, 'months' => $months];
    }

    private static function validDocumentsSql(): string
    {
        // Sales use canonical SaleFilters; purchases/returns also support
        // status=6. Legacy voids change type to 7, excluded by the type list.
        return SaleFilters::notVoidedSql()
            . ' AND (transactionType IN (0,3) OR COALESCE(transactionStatus,1) <> 6)';
    }
}
