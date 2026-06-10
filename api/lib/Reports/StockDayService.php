<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Niveles de Stock por Día (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportStockDayService.php (Fase 2 batch 1). Único cambio
 * vs el original: namespace + `final`. SQL idéntico (incluye fixes PG documentados:
 * `itemTrackInventory = true` y `stockDate::date <= ?::date` y `ORDER BY stockDate DESC`).
 *
 * Tenant: companyId bound + $roc en la sub-query de stock.
 */
final class StockDayService
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
                'itemId' => $id,
                'name'   => (string) ($f['itemName'] ?? ''),
                'sku'    => (string) ($f['itemSKU'] ?? ''),
                'cogs'   => (float) ($st ? ($st['stockOnHandCOGS'] ?? 0) : 0),
                'onHand' => (float) ($st ? ($st['stockOnHand'] ?? 0) : 0),
            ];

            $items->MoveNext();
        }
        $items->Close();

        return $rows;
    }
}
