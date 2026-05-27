<?php
/**
 * Dominio de Reportes — Ventas por Categorías (capa API, motor ERP).
 *
 * Devuelve filas CRUDAS por categoría (unidades, total, IVA, costo, descuento) en un período,
 * con el nombre de categoría ya resuelto. Sin formatear, sin HTML. El BFF calcula el % y los
 * totales; el front formatea y arma tabla/KPIs/treemap. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_categories.php (action=generalTable).
 *
 * Fixes PG: el legacy hacía `SELECT a.itemId … GROUP BY category` (válido en MySQL, error en
 * PG por columna no agrupada). Como la resta de descuentos de combos
 * (getAllCombosCompoundsDiscount) se OMITE (ese helper usa `itemSoldParent != 0` (uuid vs int)
 * → roto en PG, ya es no-op en el legacy actual), el `itemId` por grupo ya no hace falta →
 * la query agrupa por `c.categoryId` sin seleccionar itemId (PG no tiene MIN(uuid)).
 * Migrar la corrección de combos es un refinamiento aparte.
 *
 * Tenant: $roc (b-prefijado) derivado de COMPANY_ID del JWT.
 */
class ReportCategoriesService
{
    /** @return array filas [{categoryId, name, usold, total, tax, cogs, comission, discount}] ordenadas por usold desc */
    public function salesByCategory($from, $to, $roc)
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

        $categories = getAllItemCategories(); // taxonomyId → {name} (bound, PG-safe)

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
