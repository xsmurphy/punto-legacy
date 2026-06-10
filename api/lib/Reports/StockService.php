<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

/**
 * Dominio de Reportes — Niveles de Stock (multi-depósito) (API compartida, motor ERP).
 *
 * Port FIEL de panel/lib/reports/ReportStockService.php (Fase 2 batch 2). Único cambio
 * vs el original: namespace + `final`. SQL idéntico (incluye fixes PG documentados:
 * `itemTrackInventory = true`, última fila de stock por FECHA, principal.count = onHand − Σ depósitos).
 *
 * Tenant: companyId bound + $roc en taxonomy y en la sub-query de stock.
 */
final class StockService
{
    /** @return array filas [{itemId, name, sku, onHand, cogs, depots:[{locationId,locationName,min,count}], principal:{min,count}}] */
    public function levels($roc, $companyId, $outletId)
    {
        $items = ncmExecute(
            "SELECT itemId, itemName, itemSKU
             FROM item
             WHERE itemTrackInventory = true AND itemStatus = 1
               AND itemType IN ('product', 'compound') AND companyId = ?
             LIMIT 2000",
            [$companyId], false, true
        );
        if (!$items || !is_object($items)) {
            return [];
        }

        $locations = ncmExecute(
            "SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = 'location'" . $roc . " ORDER BY taxonomyName ASC",
            [], false, true, true
        );
        $locations = is_array($locations) ? $locations : [];

        $rows = [];
        while (!$items->EOF) {
            $f  = $items->fields;
            $id = (string) $f['itemId'];

            $st     = ncmExecute(
                'SELECT stockOnHand, stockOnHandCOGS FROM stock WHERE itemId = ?' . $roc . ' ORDER BY stockDate DESC LIMIT 1',
                [$id], true
            );
            $onHand = (float) ($st ? ($st['stockOnHand'] ?? 0) : 0);
            $cogs   = (float) ($st ? ($st['stockOnHandCOGS'] ?? 0) : 0);

            $depots   = [];
            $subStock = 0;
            foreach ($locations as $loc) {
                $locId = $loc['taxonomyId'];
                $toLoc = ncmExecute('SELECT toLocationCount FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1', [$locId, $id]);
                $count = $toLoc ? (float) ($toLoc['toLocationCount'] ?? 0) : 0;
                // stockTrigger del legacy usa la taxonomyId de la location como outletId (sic).
                $trig  = ncmExecute('SELECT stockTriggerCount FROM stockTrigger WHERE outletId = ? AND itemId = ? LIMIT 1', [$locId, $id]);
                $min   = $trig ? (float) ($trig['stockTriggerCount'] ?? 0) : 0;
                $subStock += $count;
                $depots[] = [
                    'locationId'   => (string) $locId,
                    'locationName' => (string) ($loc['taxonomyName'] ?? ''),
                    'min'          => $min,
                    'count'        => $count,
                ];
            }

            $trigP = ncmExecute('SELECT stockTriggerCount FROM stockTrigger WHERE outletId = ? AND itemId = ? LIMIT 1', [$outletId, $id]);
            $rows[] = [
                'itemId'    => $id,
                'name'      => (string) ($f['itemName'] ?? ''),
                'sku'       => (string) ($f['itemSKU'] ?? ''),
                'onHand'    => $onHand,
                'cogs'      => $cogs,
                'depots'    => $depots,
                'principal' => [
                    'min'   => $trigP ? (float) ($trigP['stockTriggerCount'] ?? 0) : 0,
                    'count' => $onHand - $subStock,
                ],
            ];

            $items->MoveNext();
        }
        $items->Close();

        return $rows;
    }
}
