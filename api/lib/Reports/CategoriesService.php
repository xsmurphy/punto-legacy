<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Ventas por Categorías (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportCategoriesService.php (Fase 2 batch 1). Único cambio
 * vs el original: namespace + `final` + `Taxonomy::categories($companyId)` en vez del
 * getter global del panel. SQL idéntico.
 *
 * Tenant: $roc (b-prefijado, transaction = alias b) derivado de COMPANY_ID del JWT.
 */
final class CategoriesService
{
    /** @return array filas [{categoryId, name, usold, total, tax, cogs, comission, discount}] ordenadas por usold desc */
    public function salesByCategory($from, $to, $roc, string $companyId)
    {
        $sql = 'SELECT SUM(a.itemSoldUnits)                   AS usold,
                       SUM(a.itemSoldTotal)                   AS total,
                       SUM(a.itemSoldTax)                     AS tax,
                       SUM(a.itemSoldCOGS * a.itemSoldUnits)  AS cogs,
                       SUM(a.itemSoldComission)               AS comission,
                       SUM(a.itemSoldDiscount * a.itemSoldUnits) AS discount,
                       c.categoryId                           AS category
                FROM itemSold a, transaction b, item c
                WHERE a.itemId = c.itemId
                  AND a.itemSoldDate BETWEEN ? AND ?
                  AND a.transactionId = b.transactionId' . $roc . '
                  AND b.transactionType IN (0, 3)
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
