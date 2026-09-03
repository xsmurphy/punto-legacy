<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Ventas por Categorías (API compartida, motor ERP).
 *
 * salesByCategory: gated por REPORTS_ROLLUP_ENABLED. Con rollup, lee item_sales
 * del RollupReader y re-agrega por categoryId actual del item.
 * salesByCategoryLive: cómputo original intacto (SQL directo sobre itemSold/transaction/item).
 *
 * Tenant: $roc (b-prefijado, transaction = alias b) derivado de COMPANY_ID del JWT.
 */
final class CategoriesService
{
    /**
     * @param list<string> $outletIds Alcance por sucursal (`OutletScope::effectiveIds()`);
     *                                `[]` = sin filtro, 2+ = consolidado acotado. La rama
     *                                live no lo mira: ya filtra por `$roc`.
     */
    public function salesByCategory($from, $to, $roc, string $companyId, bool $forceRollup = false, array $outletIds = []): array
    {
        if (!$forceRollup && empty($_ENV['REPORTS_ROLLUP_ENABLED'])) {
            return $this->salesByCategoryLive($from, $to, $roc, $companyId);
        }

        $reader    = new RollupReader();
        $itemSales = $reader->itemSalesRange($companyId, $from, $to, $outletIds);

        if (empty($itemSales)) {
            return [];
        }

        $itemIds      = array_keys($itemSales);
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params       = array_merge([$companyId], $itemIds);
        $rs = ncmExecute(
            "SELECT itemid::text AS itemid, categoryid::text AS categoryid
             FROM item
             WHERE companyid = ?
               AND itemid IN ({$placeholders})",
            $params,
            false,
            true
        );

        $itemToCategory = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $itemToCategory[(string)$f['itemid']] = $f['categoryid'] ? (string)$f['categoryid'] : '';
                $rs->MoveNext();
            }
            $rs->Close();
        }

        $grouped = [];
        foreach ($itemSales as $itemId => $m) {
            $catId = $itemToCategory[$itemId] ?? '';
            if (!isset($grouped[$catId])) {
                $grouped[$catId] = ['usold'=>0,'total'=>0,'tax'=>0,'cogs'=>0,'comission'=>0,'discount'=>0];
            }
            $grouped[$catId]['usold']     += $m['qty'];
            $grouped[$catId]['total']     += $m['total'];
            $grouped[$catId]['tax']       += $m['tax'];
            $grouped[$catId]['cogs']      += $m['cogs'];
            $grouped[$catId]['comission'] += $m['comission'];
            $grouped[$catId]['discount']  += $m['discount'];
        }

        $categories = Taxonomy::categories($companyId);

        $rows = [];
        foreach ($grouped as $catId => $g) {
            $rows[] = [
                'categoryId' => $catId,
                'name'       => ($catId !== '' && isset($categories[$catId])) ? $categories[$catId]['name'] : 'Sin Categoría',
                'usold'      => (float) $g['usold'],
                'total'      => (float) $g['total'],
                'tax'        => (float) $g['tax'],
                'cogs'       => (float) $g['cogs'],
                'comission'  => (float) $g['comission'],
                'discount'   => (float) $g['discount'],
            ];
        }

        usort($rows, fn($a, $b) => $b['usold'] <=> $a['usold']);

        return $rows;
    }

    public function salesByCategoryLive($from, $to, $roc, string $companyId): array
    {
        $sql = 'SELECT SUM(a.itemSoldUnits)                   AS usold,
                       SUM(a.itemSoldTotal)                   AS total,
                       SUM(a.itemSoldTax)                     AS tax,
                       SUM(a.itemSoldCOGS * a.itemSoldUnits)  AS cogs,
                       SUM(a.itemSoldComission)               AS comission,
                       SUM(a.itemSoldDiscount)                   AS discount,
                       c.categoryId                           AS category
                FROM itemSold a, transaction b, item c
                WHERE a.itemId = c.itemId
                  AND a.itemSoldDate BETWEEN ? AND ?
                  AND a.transactionId = b.transactionId' . $roc . '
                  AND b.transactionType IN (0, 3)
                  AND ' . SaleFilters::notVoidedSql('b') . '
                GROUP BY c.categoryId
                ORDER BY usold DESC';

        $res = ncmExecute($sql, [$from, $to], false, true);
        if (!$res || !is_object($res)) {
            return [];
        }

        $categories = Taxonomy::categories($companyId);

        $rows = [];
        while (!$res->EOF) {
            $f   = $res->fields;
            $cat = $f['category'];
            $rows[] = [
                'categoryId' => $cat ? (string) $cat : '',
                'name'       => ($cat && isset($categories[$cat])) ? $categories[$cat]['name'] : 'Sin Categoría',
                'usold'      => (float) $f['usold'],
                'total'      => (float) $f['total'],
                'tax'        => (float) $f['tax'],
                'cogs'       => (float) $f['cogs'],
                'comission'  => (float) $f['comission'],
                'discount'   => (float) $f['discount'],
            ];
            $res->MoveNext();
        }
        $res->Close();

        return $rows;
    }
}
