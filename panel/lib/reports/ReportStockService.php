<?php
/**
 * Dominio de Reportes — Niveles de Stock (multi-depósito) (capa API, motor ERP).
 *
 * Por cada ítem que rastrea inventario: existencia total + valor al costo + desglose por
 * depósito (cantidad en cada `toLocation` + stock mínimo de `stockTrigger`) + fila "Principal"
 * (lo no asignado a depósitos). Filas CRUDAS (números), sin formatear, sin HTML. El front
 * formatea y calcula los colores/% de las barras de alerta. Ver REGLA RAÍZ 2.
 *
 * Reemplaza la lógica inline de panel/a_report_stock.php (action=generalTable).
 *
 * Fixes PG: `itemTrackInventory = 1` (bool=int) → `= true`. El gate `OUTLET_ID < 2` (uuid<int,
 * roto en PG → siempre "seleccione sucursal") se reemplaza por un check de outlet válido en el
 * endpoint (flag needsOutlet). El stock total/costo se computa con la ÚLTIMA fila de `stock` por
 * FECHA (no via getAllItemStock, que ordena por stockId — uuid aleatorio en PG → no cronológico).
 * Desviación del legacy: `principal.count` = onHand − Σ(depósitos) (lo correcto); el legacy
 * restaba solo el último depósito (bug, además nunca corría en PG).
 *
 * Tenant: companyId bound + $roc (getROC) en taxonomy y en la sub-query de stock.
 */
class ReportStockService
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

            // Stock total/costo = última fila de stock por FECHA (no por stockId uuid), scopeado por outlet.
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
