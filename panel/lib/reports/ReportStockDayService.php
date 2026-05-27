<?php
/**
 * Dominio de Reportes — Niveles de Stock por Día (capa API, motor ERP).
 *
 * Para cada ítem que rastrea inventario, su existencia (onHand) y valor al costo
 * (stockOnHandCOGS) según el último movimiento de stock hasta una fecha de corte.
 * Filas CRUDAS (números), sin formatear, sin HTML. El front formatea y arma la tabla.
 *
 * Reemplaza la lógica inline de panel/a_report_stock_day.php (action=generalTable).
 *
 * Fixes PG: el legacy usaba `itemTrackInventory = 1` (bool=int) → `= true`;
 * `DATE(stockDate) <= ?` (DATE() es MySQL) → `stockDate::date <= ?::date`; y
 * `ORDER BY stockId DESC` (asumía PK auto-incremental de MySQL) → `ORDER BY stockDate DESC`
 * porque en PG stockId es un uuid aleatorio (no cronológico) y el "último movimiento" debe
 * ordenarse por fecha.
 *
 * Tenant: companyId bound + $roc (getROC) en la sub-query de stock.
 */
class ReportStockDayService
{
    /** @return array filas [{itemId, name, sku, cogs, onHand}] */
    public function levels($date, $companyId, $roc, $limit = 3000)
    {
        $items = ncmExecute(
            "SELECT itemId, itemName, itemSKU
             FROM item
             WHERE itemTrackInventory = true
               AND itemStatus = 1
               AND itemType IN ('product', 'compound')
               AND companyId = ?
             ORDER BY itemName ASC
             LIMIT " . (int) $limit,
            [$companyId], false, true
        );

        if (!$items || !is_object($items)) {
            return [];
        }

        $rows = [];
        while (!$items->EOF) {
            $f  = $items->fields;
            $id = (string) $f['itemId'];

            // Último movimiento de stock del ítem hasta la fecha de corte (scopeado por $roc).
            $st = ncmExecute(
                'SELECT stockOnHand, stockOnHandCOGS
                 FROM stock
                 WHERE itemId = ?' . $roc . '
                   AND stockDate::date <= ?::date
                 ORDER BY stockDate DESC
                 LIMIT 1',
                [$id, $date], true
            );

            $rows[] = [
                'itemId'  => $id,
                'name'    => (string) ($f['itemName'] ?? ''),
                'sku'     => (string) ($f['itemSKU'] ?? ''),
                'cogs'    => (float) ($st ? ($st['stockOnHandCOGS'] ?? 0) : 0),
                'onHand'  => (float) ($st ? ($st['stockOnHand'] ?? 0) : 0),
            ];

            $items->MoveNext();
        }
        $items->Close();

        return $rows;
    }
}
