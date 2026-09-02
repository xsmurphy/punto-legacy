<?php
declare(strict_types=1);
namespace Punto\Api\Services;

require_once __DIR__ . '/InventoryCountScope.php';
require_once __DIR__ . '/../Settings/StockCountSettings.php';

use Punto\Api\Settings\StockCountSettings;

/**
 * InventoryCountService — toma física de inventario.
 *
 * El método finish() genera movimientos de ajuste via \Punto\App\Domain\Inventory::manageStock()
 * NUNCA hace INSERT/UPDATE directo en la tabla stock.
 *
 * Decisión: countedQty = NULL al finalizar se trata como "no diferencia"
 * (countedQty = expectedQty). El operador que no contó un item no genera
 * movimiento de ajuste. Esto es un default conservador — se puede cambiar
 * si el negocio prefiere ajustar a 0.
 *
 * QUÉ ítems entran en la sesión NO se decide acá: lo decide
 * `InventoryCountScope` (mig 158), compartido con el `action=preview` del
 * endpoint. Antes este método snapshoteaba todo el catálogo del tenant y el
 * outletId solo servía para la cantidad esperada — contar una sucursal traía
 * los ítems de todas las demás en 0.
 */
final class InventoryCountService
{
    /**
     * @param string[] $categoryIds  Vacío = todas las categorías.
     */
    public function create(
        string $companyId,
        string $outletId,
        ?string $locationId,
        string $startedBy,
        ?string $note,
        array $categoryIds = [],
        bool $includeZeroStock = false,
    ): array {
        global $db;

        $scope = InventoryCountScope::forRequest(
            $companyId,
            $outletId,
            $locationId,
            $categoryIds,
            $includeZeroStock,
        );
        $locationId = $scope->locationId();

        // Resolver los ítems ANTES de abrir la TX: si el alcance no incluye
        // ninguno, no queremos gastar un correlativo de documento (allocate()
        // vive dentro de la TX y el rollback lo devuelve, pero el conteo vacío
        // tampoco tiene sentido de negocio — la UI ya muestra el total con
        // action=preview antes de habilitar el botón).
        [$itemsSql, $itemsParams] = $scope->itemsQuery();
        $itemsRs = ncmExecute($itemsSql, $itemsParams, false, true);

        $lines = [];
        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $row     = $itemsRs->fields;
                $lines[] = [
                    (string) $row['itemid'],
                    (float) $row['expectedqty'],
                    (float) $row['unitcost'],
                ];
                $itemsRs->MoveNext();
            }
        }

        if ($lines === []) {
            throw new \InvalidArgumentException(
                'El alcance elegido no incluye ningún artículo. Ampliá las categorías '
                . 'o activá "Incluir artículos sin stock en la sucursal".'
            );
        }

        $db->StartTrans();

        // Correlativo del documento (F3, context/37), scope sucursal. Dentro de
        // la TX: si el conteo no persiste, el rollback devuelve el número.
        $docNumber = \Punto\Api\Documents\DocumentNumber::allocate(
            'conteo',
            \Punto\Api\Documents\DocumentNumber::SCOPE_OUTLET,
            $outletId,
            $companyId,
        );

        $sessionRow = ncmExecute(
            'INSERT INTO inventory_count (companyid, outletid, locationid, startedby, "note", docnumber, scope)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb) RETURNING inventorycountid',
            [$companyId, $outletId, $locationId, $startedBy, $note ?: null, $docNumber, $scope->toJson()]
        );

        if (!$sessionRow || empty($sessionRow['inventoryCountId'])) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear la sesión de conteo');
        }

        $countId = $sessionRow['inventoryCountId'];

        foreach ($lines as [$itemId, $onHand, $cogs]) {
            ncmExecute(
                'INSERT INTO inventory_count_item (inventorycountid, itemid, expectedqty, unitcost)
                 VALUES (?, ?, ?, ?)',
                [$countId, $itemId, $onHand, $cogs]
            );
        }

        $db->CompleteTrans();

        return ['id' => $countId, 'itemCount' => count($lines)];
    }

    /**
     * Cuántos artículos entrarían con este alcance, sin crear la sesión.
     * Alimenta el "vas a contar N artículos" del diálogo — mismo predicado
     * que usa create(), por construcción.
     *
     * @param string[] $categoryIds
     */
    public function preview(
        string $companyId,
        string $outletId,
        ?string $locationId,
        array $categoryIds = [],
        bool $includeZeroStock = false,
    ): array {
        $scope = InventoryCountScope::forRequest(
            $companyId,
            $outletId,
            $locationId,
            $categoryIds,
            $includeZeroStock,
        );

        [$sql, $params] = $scope->countQuery();
        $row = ncmExecute($sql, $params);

        return ['count' => (int) ($row['total'] ?? 0)];
    }

    public function get(string $id, string $companyId): ?array
    {
        $session = ncmExecute(
            'SELECT ic.*, u1.contactname as "startedByName", u2.contactname as "finishedByName"
             FROM inventory_count ic
             LEFT JOIN contact u1 ON u1.contactid = ic.startedby
             LEFT JOIN contact u2 ON u2.contactid = ic.finishedby
             WHERE ic.inventorycountid = ? AND ic.companyid = ?
             LIMIT 1',
            [$id, $companyId]
        );

        if (!$session) {
            return null;
        }

        // Categoría por línea: el m2m `item_category` manda (con la isPrimary
        // adelante) y la FK legacy `item.categoryid` es el fallback para los
        // ítems viejos que nunca se guardaron por el panel nuevo. Es el mismo
        // doble camino que usa InventoryCountScope para filtrar — si el filtro
        // metió un ítem por la legacy, la fila tiene que poder mostrarla.
        $itemsRs = ncmExecute(
            'SELECT ici.inventorycountitemid, ici.itemid, i.itemname as name, i.itemsku as sku,
                    ici.expectedqty, ici.countedqty, ici."difference", ici.unitcost,
                    ici.countedat, ici.countedby,
                    COALESCE(pc.categoryid, lc.categoryid) as "categoryId",
                    COALESCE(pc.name, lc.name)             as "categoryName"
             FROM inventory_count_item ici
             JOIN item i ON i.itemid = ici.itemid
             LEFT JOIN LATERAL (
                  SELECT c.categoryid, c.name
                    FROM item_category ic
                    JOIN category c ON c.categoryid = ic.categoryid
                   WHERE ic.itemid = i.itemid
                   ORDER BY ic.isprimary DESC NULLS LAST, c.name ASC
                   LIMIT 1
             ) pc ON true
             LEFT JOIN category lc ON lc.categoryid = i.categoryid
             WHERE ici.inventorycountid = ?
             ORDER BY i.itemname ASC',
            [$id],
            false,
            true
        );

        // Conteo ciego (D2 de context/63): mientras la sesión está EN PROGRESO
        // el esperado y la diferencia no salen del servidor. No es que la
        // pantalla no los pinte —eso se evade mirando la respuesta— es que no
        // se mandan, igual que `drawerBlind` con el total del arqueo.
        //
        // Al FINALIZAR sí se muestran, y tiene que ser así: el owner pidió
        // textualmente que "cada conteo finalizado debe quedar detallado en el
        // panel con sus diferencias". Ciego describe el momento de contar, no
        // el registro que queda.
        $blind = StockCountSettings::forCompany($companyId)->blind()
            && (int) $session['status'] === 1;

        $items = [];
        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $row      = $itemsRs->fields;
                $items[]  = [
                    'inventoryCountItemId' => $row['inventoryCountItemId'],
                    'itemId'      => $row['itemId'],
                    'name'        => $row['name'],
                    'sku'         => $row['sku'],
                    'categoryId'   => $row['categoryId'],
                    'categoryName' => $row['categoryName'],
                    'expectedQty' => $blind ? null : (float) $row['expectedQty'],
                    'countedQty'  => $row['countedQty'] !== null ? (float) $row['countedQty'] : null,
                    'difference'  => ($blind || $row['difference'] === null) ? null : (float) $row['difference'],
                    // El costo unitario no revela el esperado (es el costo del
                    // artículo, no una cantidad) pero SÍ compone el valor de la
                    // diferencia, que sin diferencia no se puede calcular.
                    'unitCost'    => (float) $row['unitCost'],
                    'countedAt'   => $row['countedAt'],
                ];
                $itemsRs->MoveNext();
            }
        }

        return [
            'session' => [
                'inventoryCountId' => $session['inventoryCountId'],
                // Correlativo del documento (mig 129).
                'docNumber'        => isset($session['docNumber']) ? (int) $session['docNumber'] : null,
                'companyId'        => $session['companyId'],
                'outletId'         => $session['outletId'],
                'locationId'       => $session['locationId'],
                'status'           => (int) $session['status'],
                'note'             => $session['note'],
                'startedAt'        => $session['startedAt'],
                'finishedAt'       => $session['finishedAt'],
                'startedBy'        => $session['startedBy'],
                'startedByName'    => $session['startedByName'],
                'finishedBy'       => $session['finishedBy'],
                'finishedByName'   => $session['finishedByName'],
                // Alcance con el que se abrió (mig 158). El cast a object es
                // necesario: un array PHP vacío serializa como `[]` y el
                // cliente espera un objeto — las sesiones anteriores a la
                // migración devuelven `{}` (alcance desconocido: se
                // snapshoteaba todo el tenant), no una lista vacía.
                'scope'            => (object) InventoryCountScope::decode($session['scope'] ?? null),
                // Por qué el esperado viene en null. Sin este flag la pantalla
                // no puede distinguir "conteo ciego" de "dato faltante", y el
                // copy de finalizar prometería una diferencia que no tiene.
                'blind'            => $blind,
            ],
            'items' => $items,
        ];
    }

    public function setCountedQty(string $countId, string $itemId, float $qty, string $userId, string $companyId): bool
    {
        global $db;

        $session = ncmExecute(
            'SELECT inventorycountid FROM inventory_count WHERE inventorycountid = ? AND companyid = ? AND "status" = 1 LIMIT 1',
            [$countId, $companyId]
        );
        if (!$session) {
            return false;
        }

        $db->Execute(
            'UPDATE inventory_count_item SET countedqty = ?, countedat = NOW(), countedby = ?
             WHERE inventorycountid = ? AND itemid = ?',
            [$qty, $userId, $countId, $itemId]
        );

        return (int) $db->Affected_Rows() > 0;
    }

    public function bulkSetCountedQty(string $countId, array $rows, string $userId, string $companyId): int
    {
        $session = ncmExecute(
            'SELECT inventorycountid FROM inventory_count WHERE inventorycountid = ? AND companyid = ? AND "status" = 1 LIMIT 1',
            [$countId, $companyId]
        );
        if (!$session) {
            return 0;
        }

        global $db;
        $count = 0;
        foreach ($rows as $row) {
            $db->Execute(
                'UPDATE inventory_count_item SET countedqty = ?, countedat = NOW(), countedby = ?
                 WHERE inventorycountid = ? AND itemid = ?',
                [$row['qty'], $userId, $countId, $row['itemId']]
            );
            $count += (int) $db->Affected_Rows();
        }
        return $count;
    }

    public function finish(string $id, string $companyId, string $finishedBy): array
    {
        global $db;

        $db->StartTrans();

        $session = ncmExecute(
            'SELECT * FROM inventory_count WHERE inventorycountid = ? AND companyid = ? FOR UPDATE',
            [$id, $companyId]
        );

        if (!$session) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException('Sesión no encontrada');
        }

        if ((int) $session['status'] !== 1) {
            $db->FailTrans();
            $db->CompleteTrans();
            $statusMap = [0 => 'cancelada', 2 => 'ya finalizada'];
            $label     = $statusMap[(int) $session['status']] ?? 'inválida';
            throw new \RuntimeException("La sesión está {$label}", 409);
        }

        $linesRs = ncmExecute(
            'SELECT itemid, expectedqty, countedqty, "difference", unitcost
             FROM inventory_count_item
             WHERE inventorycountid = ? AND countedqty IS NOT NULL AND "difference" != 0',
            [$id],
            false,
            true
        );

        $adjustmentsCount = 0;
        $totalCostDelta   = 0.0;
        $outletId         = $session['outletId'];
        $locationId       = $session['locationId'] ?: null;

        if ($linesRs) {
            while (!$linesRs->EOF) {
                $row        = $linesRs->fields;
                $itemId     = $row['itemId'];
                $difference = (float) $row['difference'];
                $unitCost   = (float) $row['unitCost'];
                $type       = $difference > 0 ? '+' : '-';

                \Punto\App\Domain\Inventory::manageStock([
                    'itemId'        => $itemId,
                    'source'        => 'inventory_count',
                    'count'         => abs($difference),
                    'type'          => $type,
                    'cogs'          => $unitCost,
                    'userId'        => $finishedBy,
                    'transactionId' => null,
                    'outletId'      => $outletId,
                    'locationId'    => $locationId,
                    'note'          => "count #{$id}",
                    'date'          => date('Y-m-d H:i:s'),
                    'companyId'     => $companyId,
                ]);

                $totalCostDelta   += $difference * $unitCost;
                $adjustmentsCount++;
                $linesRs->MoveNext();
            }
        }

        $db->Execute(
            'UPDATE inventory_count SET "status" = 2, finishedat = NOW(), finishedby = ? WHERE inventorycountid = ?',
            [$finishedBy, $id]
        );

        $db->CompleteTrans();

        // El publish de 'item' ahora vive en Inventory::manageStock() (única
        // puerta de todo movimiento de stock, dedup por request) — este caller
        // ya no necesita avisar a mano. Ver context/15-realtime-sync-plan.md.

        return [
            'adjustmentsCount' => $adjustmentsCount,
            'totalCostDelta'   => $totalCostDelta,
        ];
    }

    public function cancel(string $id, string $companyId): bool
    {
        $session = ncmExecute(
            'SELECT "status" FROM inventory_count WHERE inventorycountid = ? AND companyid = ? LIMIT 1',
            [$id, $companyId]
        );

        if (!$session) {
            return false;
        }

        if ((int) $session['status'] === 2) {
            throw new \RuntimeException('No se puede cancelar una sesión ya finalizada', 409);
        }

        global $db;
        $db->Execute(
            'UPDATE inventory_count SET "status" = 0 WHERE inventorycountid = ? AND companyid = ? AND "status" = 1',
            [$id, $companyId]
        );

        return (int) $db->Affected_Rows() > 0;
    }

    public function list(string $companyId, ?string $outletId, ?int $status, int $limit, int $offset): array
    {
        // Columnas CALIFICADAS con el alias `ic`, y el mismo alias en el COUNT.
        //
        // Sin calificar, este WHERE es válido para el COUNT (una sola tabla) y
        // ROMPE en la query de filas, que joinea `outlet o`: `companyid` y
        // `outletid` existen en las dos tablas y Postgres corta con 42702.
        // Nunca saltó porque el único caller —el listado del panel— no manda
        // `outletId`, así que la rama con el filtro no se ejercitaba.
        $where  = ['ic.companyid = ?'];
        $params = [$companyId];

        if ($outletId !== null) {
            $where[]  = 'ic.outletid = ?';
            $params[] = $outletId;
        }

        if ($status !== null) {
            $where[]  = 'ic."status" = ?';
            $params[] = $status;
        }

        $whereStr = implode(' AND ', $where);

        $totalRow = ncmExecute(
            "SELECT COUNT(*) as total FROM inventory_count ic WHERE {$whereStr}",
            $params
        );
        $total = (int) ($totalRow['total'] ?? 0);

        $countParams = array_merge($params, [$limit, $offset]);
        $rowsRs = ncmExecute(
            "SELECT ic.inventorycountid, ic.docnumber, ic.outletid, ic.locationid, ic.\"status\",
                    ic.startedat, ic.finishedat, ic.\"note\",
                    o.outletname,
                    t.taxonomyname as \"locationName\",
                    (SELECT COUNT(*) FROM inventory_count_item ici WHERE ici.inventorycountid = ic.inventorycountid) as \"totalItems\",
                    (SELECT COUNT(*) FROM inventory_count_item ici WHERE ici.inventorycountid = ic.inventorycountid AND ici.countedqty IS NOT NULL) as \"countedItems\",
                    (SELECT COALESCE(SUM(ici.\"difference\" * ici.unitcost), 0) FROM inventory_count_item ici WHERE ici.inventorycountid = ic.inventorycountid AND ici.countedqty IS NOT NULL AND ici.\"difference\" IS NOT NULL) as \"totalCostDelta\"
             FROM inventory_count ic
             JOIN outlet o ON o.outletid = ic.outletid
             LEFT JOIN taxonomy t ON t.taxonomyid = ic.locationid
             WHERE {$whereStr}
             ORDER BY ic.startedat DESC
             LIMIT ? OFFSET ?",
            $countParams,
            false,
            true
        );

        // Mismo criterio que get(): con conteo ciego, una sesión EN PROGRESO no
        // publica su diferencia acumulada. Publicarla acá sería la puerta de
        // atrás — el cajero abre el listado y deduce el esperado del artículo
        // que acaba de cargar.
        $blind = StockCountSettings::forCompany($companyId)->blind();

        $rows = [];
        if ($rowsRs) {
            while (!$rowsRs->EOF) {
                $r      = $rowsRs->fields;
                $rows[] = [
                    'inventoryCountId' => $r['inventoryCountId'],
                    'docNumber'        => isset($r['docNumber']) ? (int) $r['docNumber'] : null,
                    'outletId'         => $r['outletId'],
                    'outletName'       => $r['outletname'],
                    'locationId'       => $r['locationId'],
                    'locationName'     => $r['locationName'],
                    'status'           => (int) $r['status'],
                    'startedAt'        => $r['startedAt'],
                    'finishedAt'       => $r['finishedAt'],
                    'note'             => $r['note'],
                    'totalItems'       => (int) $r['totalItems'],
                    'countedItems'     => (int) $r['countedItems'],
                    'totalCostDelta'   => ($blind && (int) $r['status'] === 1)
                        ? null
                        : (float) $r['totalCostDelta'],
                ];
                $rowsRs->MoveNext();
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
