<?php
declare(strict_types=1);

namespace Punto\Api\Reports;

use Punto\App\Domain\Inventory;

/**
 * Dominio de Reportes — Niveles de Stock (multi-depósito).
 *
 * F1 de context/52-stock-ledger-unica-fuente.md — reescrito sobre el LEDGER
 * como única fuente de verdad (D1). Lo anterior (port fiel del panel legacy)
 * mezclaba tres fuentes distintas y ninguna coincidía con el resto del
 * sistema:
 *
 *   - `onHand` salía del SNAPSHOT `stock.stockOnHand` de la última fila. El
 *     snapshot es un acumulado cacheado al INSERT: un movimiento con fecha
 *     anterior (una compra cargada con fecha de ayer) se mete en el medio del
 *     historial y las filas posteriores quedan con el saldo viejo — el "bug
 *     del salmón". El tab Stock de la ficha y el POS ya leían
 *     `SUM(stockCount)`, así que el reporte mostraba OTRO número para el mismo
 *     ítem y la misma sucursal.
 *   - `depots[].count` salía de `toLocation`: tabla espejo sin scope de outlet
 *     ni de company, sin UNIQUE, y que solo se decrementaba (los ingresos
 *     entraban con locationId=null) → derivaba a negativo sola. Ya no se
 *     escribe (context/52, D4).
 *   - `principal.count` era `onHand − Σ depósitos`, definición INCOMPATIBLE
 *     con la del breakdown (`SUM WHERE locationId IS NULL`): cuando el
 *     movimiento quedaba escrito en las dos tablas, la resta lo contaba dos
 *     veces.
 *   - `min` salía de `stockTrigger`, tabla SIN writer vivo → siempre 0. El
 *     semáforo de mínimos existía en el listado de ítems y en el POS
 *     (`item.itemMinStock`) pero jamás en este reporte (context/52, D3).
 *
 * Ahora las cuatro cifras salen de la MISMA fuente: `SUM(stock.stockCount)`
 * por (ítem, sucursal), agrupado por `locationId` para el desglose. El
 * principal es el grupo `locationId IS NULL` — no una resta, así que la suma
 * de principal + depósitos da `onHand` por construcción. El mínimo es
 * `item.itemMinStock`, el mismo que lee todo el resto del sistema.
 *
 * De paso se va el N+1: antes eran 2 queries por ítem MÁS 2 por cada par
 * (ítem, depósito) — con 2000 ítems y 3 depósitos, ~16.000 queries por
 * request. Ahora son 4 fijas y la agregación la hace PG.
 *
 * Tenant: companyId y outletId van BINDEADOS. El `$roc` (fragmento SQL
 * interpolado " AND companyId=… AND outletId=…" que armaba `Roc::build`) se
 * RETIRÓ de la firma: ninguna query lo concatena ya, y dejarlo como parámetro
 * muerto invita a que alguien lo vuelva a interpolar.
 */
final class StockService
{
    /** @return array filas [{itemId, name, sku, onHand, cogs, depots:[{locationId,locationName,min,count}], principal:{min,count}}] */
    public function levels($companyId, $outletId)
    {
        $companyId = (string) $companyId;
        $outletId  = (string) $outletId;

        $items = ncmExecute(
            "SELECT itemId, itemName, itemSKU, itemMinStock
             FROM item
             WHERE itemTrackInventory = true AND itemStatus = 1
               AND itemType IN ('product', 'compound') AND companyId = ?
             ORDER BY itemName ASC
             LIMIT 2000",
            [$companyId], false, true
        );
        if (!$items || !is_object($items)) {
            return [];
        }

        $locations = ncmExecute(
            "SELECT taxonomyId, taxonomyName FROM taxonomy
              WHERE taxonomyType = 'location' AND companyId = ? AND outletId = ?
              ORDER BY taxonomyName ASC",
            [$companyId, $outletId], false, true, true
        );
        $locations = is_array($locations) ? $locations : [];

        // Saldo + costo promedio de TODOS los ítems de la sucursal en una sola
        // query — lector único (D2 de context/52).
        //
        // Este reporte exige UNA sucursal (`stock.php` rechaza el modo "Todas"
        // con `needsOutlet`), así que el alcance sigue siendo un valor único y
        // se envuelve acá para el lector, que ahora habla en listas.
        $balances = Inventory::onHandBulk($companyId, $outletId !== '' ? [$outletId] : []);

        // Desglose por depósito: las MISMAS filas del ledger, agrupadas por
        // locationId. La suma de los grupos de un ítem es exactamente su
        // onHand — invariante por construcción, no por disciplina de código.
        $byLocation = $this->breakdownByLocation($companyId, $outletId);

        $rows = [];
        while (!$items->EOF) {
            $f  = $items->fields;
            $id = (string) $f['itemId'];

            $onHand = (float) ($balances[$id]['onHand'] ?? 0);
            $cogs   = (float) ($balances[$id]['cogs'] ?? 0);

            // Un solo mínimo por ítem: `item.itemMinStock` (mig 133). NULL
            // ("no se controla por mínimo") se presenta como 0 para no cambiar
            // la forma del payload que ya consume el front de este reporte.
            $min = isset($f['itemMinStock']) && $f['itemMinStock'] !== null
                ? (float) $f['itemMinStock']
                : 0.0;

            $groups = $byLocation[$id] ?? [];

            $depots     = [];
            $listedSum  = 0.0;
            foreach ($locations as $loc) {
                $locId      = (string) $loc['taxonomyId'];
                $count      = (float) ($groups[$locId] ?? 0);
                $listedSum += $count;
                $depots[]   = [
                    'locationId'   => $locId,
                    'locationName' => (string) ($loc['taxonomyName'] ?? ''),
                    // El mínimo es del ÍTEM, así que se repite en cada
                    // depósito. Antes venía de `stockTrigger` por depósito,
                    // pero esa tabla no tenía writer (siempre 0), así que el
                    // umbral por depósito nunca existió de verdad — no se
                    // pierde un dato, se deja de fingir que había uno. Si
                    // alguna vez hace falta un mínimo POR depósito, es una
                    // columna nueva, no esta tabla.
                    'min'          => $min,
                    'count'        => $count,
                ];
            }

            // Todo saldo que NO cayó en un depósito listado va al principal:
            // el `locationId IS NULL` de siempre MÁS cualquier grupo cuyo
            // `locationId` ya no sea una taxonomy 'location' viva de esta
            // sucursal (depósito borrado, o movimiento viejo apuntando a otro
            // outlet). Si no, ese stock desaparecía de la vista aunque siguiera
            // contando en `onHand` y la fila no cerraba.
            $principalCount = $onHand - $listedSum;

            $rows[] = [
                'itemId'    => $id,
                'name'      => (string) ($f['itemName'] ?? ''),
                'sku'       => (string) ($f['itemSKU'] ?? ''),
                'onHand'    => $onHand,
                'cogs'      => $cogs,
                'depots'    => $depots,
                'principal' => [
                    'min'   => $min,
                    // Stock sin depósito listado — en el caso normal, el grupo
                    // `locationId IS NULL` del ledger, misma definición que
                    // `StockMovementsService::breakdown()` y que el tab Stock
                    // de la ficha. NO es la vieja resta contra `toLocation`:
                    // acá los dos lados salen de las MISMAS filas, así que
                    // principal + depósitos = onHand por construcción.
                    'count' => $principalCount,
                ],
            ];

            $items->MoveNext();
        }
        $items->Close();

        return $rows;
    }

    /**
     * Saldo por (ítem, depósito) de una sucursal, derivado del ledger.
     * Clave `''` = principal (`locationId IS NULL`; las claves de array de PHP
     * no pueden ser null).
     *
     * @return array<string,array<string,float>> itemId => locationId => saldo
     */
    private function breakdownByLocation(string $companyId, string $outletId): array
    {
        $rs = ncmExecute(
            // Fence de tenant por el MODELO (JOIN a `outlet`), no por la
            // columna denormalizada `stock.companyId` — mismo criterio que
            // `Inventory::onHandBulk()`, para que las dos mitades de la fila
            // (saldo y desglose) no puedan discrepar por una denormalización
            // mal escrita.
            // El depósito efectivo consolida las filas históricas con
            // `locationId IS NULL` en el depósito por defecto de la sucursal
            // (context/52): sin esto el mismo depósito salía dos veces con el
            // saldo partido. Definición única en Inventory::ledgerLocationId().
            "SELECT s.itemId, " . \Punto\App\Domain\Inventory::ledgerLocationId() . " AS locationId,
                    COALESCE(SUM(s.stockCount), 0) AS qty
               FROM stock s
               JOIN outlet o ON o.outletId = s.outletId"
            . \Punto\App\Domain\Inventory::ledgerLocationJoin() .
             "WHERE o.companyId = ? AND s.outletId = ?
              GROUP BY s.itemId, " . \Punto\App\Domain\Inventory::ledgerLocationId(),
            [$companyId, $outletId],
            false,
            true
        );

        $out = [];
        if ($rs !== false && is_object($rs)) {
            while (!$rs->EOF) {
                $itemId = (string) ($rs->fields['itemid'] ?? $rs->fields['itemId'] ?? '');
                $locId  = $rs->fields['locationid'] ?? $rs->fields['locationId'] ?? null;
                $out[$itemId][(string) ($locId ?? '')] = (float) ($rs->fields['qty'] ?? 0);
                $rs->MoveNext();
            }
            $rs->Close();
        }

        return $out;
    }
}
