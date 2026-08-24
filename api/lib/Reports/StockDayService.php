<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\App\Domain\Inventory;

/**
 * Dominio de Reportes — Niveles de Stock por Día.
 *
 * F1 de context/52-stock-ledger-unica-fuente.md: el saldo AL CIERRE de una
 * fecha es `SUM(stockCount) WHERE stockDate <= corte` — la definición misma
 * del ledger (D1), y la única que sobrevive a un movimiento cargado con fecha
 * retroactiva.
 *
 * Lo que había antes leía el SNAPSHOT `stockOnHand` de la última fila anterior
 * al corte, con dos defectos encadenados:
 *   1. El snapshot es un acumulado cacheado al INSERT. Una compra fechada
 *      ayer se inserta en el medio del historial y las filas posteriores
 *      quedan con el acumulado viejo — el reporte del día devolvía el saldo
 *      de antes de esa compra. (`Inventory::rebuildLedger()` repostea la
 *      cadena, pero el reporte no debería depender de que el reposteo haya
 *      corrido.)
 *   2. `ORDER BY stockDate DESC` SIN desempate por `stockId`: una venta
 *      escribe varias filas con la misma `stockDate`, así que PG devolvía una
 *      fila arbitraria de ese grupo. Dos requests idénticos podían dar
 *      números distintos.
 *
 * El costo (`cogs`) SÍ sale de la fila vigente — es un promedio ponderado
 * móvil, no un acumulado sumable — pero ahora con `stockId DESC` de desempate
 * y ponderado por unidades cuando se agregan varias sucursales. Todo eso vive
 * en `Inventory::onHandBulk()`, el lector único (D2).
 *
 * De paso se va el N+1: antes una query por ítem (3000 ítems = 3000 queries).
 * Ahora son 2 fijas.
 */
final class StockDayService
{
    /**
     * @param string  $date     Corte inclusivo `YYYY-MM-DD[ HH:MM:SS]`.
     * @param string  $outletId Sucursal; `''` = todas las de la compañía
     *                          (modo "Todas" del selector de sucursal).
     * @return array filas [{itemId, name, sku, cogs, onHand}]
     */
    public function levels($date, $companyId, $outletId = '', $limit = 3000)
    {
        $companyId = (string) $companyId;
        $outletId  = (string) $outletId;

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

        // Saldo y costo al corte, en una sola query (lector único, D2).
        $balances = Inventory::onHandBulk($companyId, $outletId, (string) $date);

        $rows = [];
        while (!$items->EOF) {
            $f  = $items->fields;
            $id = (string) $f['itemId'];

            $rows[] = [
                'itemId' => $id,
                'name'   => (string) ($f['itemName'] ?? ''),
                'sku'    => (string) ($f['itemSKU'] ?? ''),
                'cogs'   => (float) ($balances[$id]['cogs'] ?? 0),
                'onHand' => (float) ($balances[$id]['onHand'] ?? 0),
            ];

            $items->MoveNext();
        }
        $items->Close();

        return $rows;
    }
}
