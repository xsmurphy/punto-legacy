<?php
/**
 * Dominio de Reportes — Ventas por Marca (capa API, motor ERP).
 *
 * Filas CRUDAS por marca (unidades, total, IVA, costo, descuento) en un período, con el
 * nombre resuelto. Sin formatear, sin HTML. El BFF calcula % + subtotal + totales; el front
 * formatea y arma tabla/KPIs/treemap. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_brands.php (action=generalTable). El bloque
 * ad-hoc `?doit=beibe` del legacy (dump hardcodeado con USE INDEX) NO se migra.
 *
 * Fix PG (igual que categories): se quita el `SELECT a.itemId … GROUP BY brand` (error en PG;
 * PG no tiene MIN(uuid)) — se agrupa por `b.brandId` sin seleccionar itemId.
 *
 * Tenant: $roc (c-prefijado, transaction = alias c) derivado de COMPANY_ID del JWT.
 */
class ReportBrandsService
{
    /** @return array filas [{brandId, name, usold, total, tax, cogs, discount}] ordenadas por usold desc */
    public function salesByBrand($from, $to, $roc)
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

        $brands = getAllItemBrands(); // taxonomyId → {name} (bound, PG-safe)

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
