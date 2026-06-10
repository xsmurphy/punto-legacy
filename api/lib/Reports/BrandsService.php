<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Ventas por Marca (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportBrandsService.php (Fase 2 batch 1). Único cambio
 * vs el original: namespace + `final` + `Taxonomy::brands($companyId)` en vez del helper
 * global del panel (que no existe en /app — ver `Taxonomy`). SQL idéntico.
 *
 * Tenant: $roc (c-prefijado, transaction = alias c) derivado de COMPANY_ID del JWT.
 */
final class BrandsService
{
    /** @return array filas [{brandId, name, usold, total, tax, cogs, discount}] ordenadas por usold desc */
    public function salesByBrand($from, $to, $roc, string $companyId)
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
