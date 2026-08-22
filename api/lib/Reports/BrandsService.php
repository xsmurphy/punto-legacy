<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Ventas por Marca (API compartida, motor ERP).
 *
 * salesByBrand: gated por REPORTS_ROLLUP_ENABLED. Con rollup, lee item_sales
 * del RollupReader y re-agrega por brandId actual del item.
 * salesByBrandLive: cómputo original intacto (SQL directo sobre itemSold/item/transaction).
 *
 * Tenant: $roc (c-prefijado, transaction = alias c) derivado de COMPANY_ID del JWT.
 */
final class BrandsService
{
    public function salesByBrand($from, $to, $roc, string $companyId, bool $forceRollup = false, ?string $outletId = null): array
    {
        if (!$forceRollup && empty($_ENV['REPORTS_ROLLUP_ENABLED'])) {
            return $this->salesByBrandLive($from, $to, $roc, $companyId);
        }

        $reader    = new RollupReader();
        $itemSales = $reader->itemSalesRange($companyId, $from, $to, $outletId);

        if (empty($itemSales)) {
            return [];
        }

        $itemIds      = array_keys($itemSales);
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params       = array_merge([$companyId], $itemIds);
        $rs = ncmExecute(
            "SELECT itemid::text AS itemid, brandid::text AS brandid
             FROM item
             WHERE companyid = ?
               AND itemid IN ({$placeholders})",
            $params,
            false,
            true
        );

        $itemToBrand = [];
        if ($rs && is_object($rs)) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $itemToBrand[(string)$f['itemid']] = $f['brandid'] ? (string)$f['brandid'] : '';
                $rs->MoveNext();
            }
            $rs->Close();
        }

        $grouped = [];
        foreach ($itemSales as $itemId => $m) {
            $brandId = $itemToBrand[$itemId] ?? '';
            if (!isset($grouped[$brandId])) {
                $grouped[$brandId] = ['usold'=>0,'total'=>0,'tax'=>0,'cogs'=>0,'discount'=>0];
            }
            $grouped[$brandId]['usold']    += $m['qty'];
            $grouped[$brandId]['total']    += $m['total'];
            $grouped[$brandId]['tax']      += $m['tax'];
            $grouped[$brandId]['cogs']     += $m['cogs'];
            // mig 160: `discount` ya es SUM(itemSoldDiscount) plano — el
            // `discountFlat` que se leía acá existía solo porque la columna
            // `discount` del rollup venía inflada por *ABS(units).
            $grouped[$brandId]['discount'] += $m['discount'];
        }

        $brands = Taxonomy::brands($companyId);

        $rows = [];
        foreach ($grouped as $brandId => $g) {
            $rows[] = [
                'brandId'  => $brandId,
                'name'     => ($brandId !== '' && isset($brands[$brandId])) ? $brands[$brandId]['name'] : 'Sin Marca',
                'usold'    => (float) $g['usold'],
                'total'    => (float) $g['total'],
                'tax'      => (float) $g['tax'],
                'cogs'     => (float) $g['cogs'],
                'discount' => (float) $g['discount'],
            ];
        }

        usort($rows, fn($a, $b) => $b['usold'] <=> $a['usold']);

        return $rows;
    }

    public function salesByBrandLive($from, $to, $roc, string $companyId): array
    {
        $sql = 'SELECT SUM(a.itemSoldUnits)                   AS usold,
                       SUM(a.itemSoldTotal)                   AS total,
                       SUM(a.itemSoldCOGS * a.itemSoldUnits)  AS cogs,
                       SUM(a.itemSoldTax)                     AS tax,
                       SUM(a.itemSoldDiscount)                AS discount,
                       b.brandId                              AS brand
                FROM itemSold a, item b, transaction c
                WHERE a.itemId = b.itemId
                  AND a.itemSoldDate BETWEEN ? AND ?
                  AND a.transactionId = c.transactionId' . $roc . '
                  AND c.transactionType IN (0, 3)
                  AND ' . SaleFilters::notVoidedSql('c') . '
                GROUP BY b.brandId
                ORDER BY usold DESC';

        $res = ncmExecute($sql, [$from, $to], false, true);
        if (!$res || !is_object($res)) {
            return [];
        }

        $brands = Taxonomy::brands($companyId);

        $rows = [];
        while (!$res->EOF) {
            $f  = $res->fields;
            $br = $f['brand'];
            $rows[] = [
                'brandId'  => $br ? (string) $br : '',
                'name'     => ($br && isset($brands[$br])) ? $brands[$br]['name'] : 'Sin Marca',
                'usold'    => (float) $f['usold'],
                'total'    => (float) $f['total'],
                'tax'      => (float) $f['tax'],
                'cogs'     => (float) $f['cogs'],
                'discount' => (float) $f['discount'],
            ];
            $res->MoveNext();
        }
        $res->Close();

        return $rows;
    }
}
