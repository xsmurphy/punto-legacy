<?php
declare(strict_types=1);
namespace Punto\Api\Services;

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
 */
final class InventoryCountService
{
    public function create(string $companyId, string $outletId, ?string $locationId, string $startedBy, ?string $note): array
    {
        global $db;

        $outlet = ncmExecute(
            'SELECT "outletId" FROM outlet WHERE "outletId" = ? AND "companyId" = ? LIMIT 1',
            [$outletId, $companyId]
        );
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        $db->StartTrans();

        $sessionRow = ncmExecute(
            'INSERT INTO inventory_count ("companyId", "outletId", "locationId", "startedBy", "note")
             VALUES (?, ?, ?, ?, ?) RETURNING "inventoryCountId"',
            [$companyId, $outletId, $locationId ?: null, $startedBy, $note ?: null]
        );

        if (!$sessionRow || empty($sessionRow['inventoryCountId'])) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear la sesión de conteo');
        }

        $countId = $sessionRow['inventoryCountId'];

        $items = ncmExecute(
            'SELECT "itemId" FROM item WHERE "companyId" = ? AND "itemStatus" = 1 AND "itemTrackInventory" >= 1',
            [$companyId],
            false,
            true
        );

        $itemCount = 0;
        if ($items) {
            while (!$items->EOF) {
                $itemId = $items->fields['itemId'];

                if ($locationId) {
                    $stockRow = ncmExecute(
                        'SELECT "toLocationCount" as onHand FROM "toLocation" WHERE "locationId" = ? AND "itemId" = ? LIMIT 1',
                        [$locationId, $itemId]
                    );
                    $onHand = $stockRow ? (float) $stockRow['onHand'] : 0.0;
                    $cogs   = 0.0;
                } else {
                    $stockRow = ncmExecute(
                        'SELECT "stockOnHand", "stockOnHandCOGS" FROM stock WHERE "itemId" = ? AND "outletId" = ? ORDER BY "stockDate" DESC, "stockId" DESC LIMIT 1',
                        [$itemId, $outletId]
                    );
                    $onHand = $stockRow ? (float) $stockRow['stockOnHand'] : 0.0;
                    $cogs   = $stockRow ? (float) $stockRow['stockOnHandCOGS'] : 0.0;
                }

                ncmExecute(
                    'INSERT INTO inventory_count_item ("inventoryCountId", "itemId", "expectedQty", "unitCost")
                     VALUES (?, ?, ?, ?)',
                    [$countId, $itemId, $onHand, $cogs]
                );

                $itemCount++;
                $items->MoveNext();
            }
        }

        $db->CompleteTrans();

        return ['id' => $countId, 'itemCount' => $itemCount];
    }

    public function get(string $id, string $companyId): ?array
    {
        $session = ncmExecute(
            'SELECT ic.*, u1."userName" as "startedByName", u2."userName" as "finishedByName"
             FROM inventory_count ic
             LEFT JOIN "user" u1 ON u1."userId" = ic."startedBy"
             LEFT JOIN "user" u2 ON u2."userId" = ic."finishedBy"
             WHERE ic."inventoryCountId" = ? AND ic."companyId" = ?
             LIMIT 1',
            [$id, $companyId]
        );

        if (!$session) {
            return null;
        }

        $itemsRs = ncmExecute(
            'SELECT ici."inventoryCountItemId", ici."itemId", i."itemName" as name, i."itemSku" as sku,
                    ici."expectedQty", ici."countedQty", ici."difference", ici."unitCost",
                    ici."countedAt", ici."countedBy"
             FROM inventory_count_item ici
             JOIN item i ON i."itemId" = ici."itemId"
             WHERE ici."inventoryCountId" = ?
             ORDER BY i."itemName" ASC',
            [$id],
            false,
            true
        );

        $items = [];
        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $row      = $itemsRs->fields;
                $items[]  = [
                    'inventoryCountItemId' => $row['inventoryCountItemId'],
                    'itemId'      => $row['itemId'],
                    'name'        => $row['name'],
                    'sku'         => $row['sku'],
                    'expectedQty' => (float) $row['expectedQty'],
                    'countedQty'  => $row['countedQty'] !== null ? (float) $row['countedQty'] : null,
                    'difference'  => $row['difference'] !== null ? (float) $row['difference'] : null,
                    'unitCost'    => (float) $row['unitCost'],
                    'countedAt'   => $row['countedAt'],
                ];
                $itemsRs->MoveNext();
            }
        }

        return [
            'session' => [
                'inventoryCountId' => $session['inventoryCountId'],
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
            ],
            'items' => $items,
        ];
    }

    public function setCountedQty(string $countId, string $itemId, float $qty, string $userId, string $companyId): bool
    {
        global $db;

        $session = ncmExecute(
            'SELECT "inventoryCountId" FROM inventory_count WHERE "inventoryCountId" = ? AND "companyId" = ? AND "status" = 1 LIMIT 1',
            [$countId, $companyId]
        );
        if (!$session) {
            return false;
        }

        $db->Execute(
            'UPDATE inventory_count_item SET "countedQty" = ?, "countedAt" = NOW(), "countedBy" = ?
             WHERE "inventoryCountId" = ? AND "itemId" = ?',
            [$qty, $userId, $countId, $itemId]
        );

        return (int) $db->Affected_Rows() > 0;
    }

    public function bulkSetCountedQty(string $countId, array $rows, string $userId, string $companyId): int
    {
        $session = ncmExecute(
            'SELECT "inventoryCountId" FROM inventory_count WHERE "inventoryCountId" = ? AND "companyId" = ? AND "status" = 1 LIMIT 1',
            [$countId, $companyId]
        );
        if (!$session) {
            return 0;
        }

        global $db;
        $count = 0;
        foreach ($rows as $row) {
            $db->Execute(
                'UPDATE inventory_count_item SET "countedQty" = ?, "countedAt" = NOW(), "countedBy" = ?
                 WHERE "inventoryCountId" = ? AND "itemId" = ?',
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
            'SELECT * FROM inventory_count WHERE "inventoryCountId" = ? AND "companyId" = ? FOR UPDATE',
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
            'SELECT "itemId", "expectedQty", "countedQty", "difference", "unitCost"
             FROM inventory_count_item
             WHERE "inventoryCountId" = ? AND "countedQty" IS NOT NULL AND "difference" != 0',
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
            'UPDATE inventory_count SET "status" = 2, "finishedAt" = NOW(), "finishedBy" = ? WHERE "inventoryCountId" = ?',
            [$finishedBy, $id]
        );

        $db->CompleteTrans();

        realtimePublish('item', 'update', null);

        return [
            'adjustmentsCount' => $adjustmentsCount,
            'totalCostDelta'   => $totalCostDelta,
        ];
    }

    public function cancel(string $id, string $companyId): bool
    {
        $session = ncmExecute(
            'SELECT "status" FROM inventory_count WHERE "inventoryCountId" = ? AND "companyId" = ? LIMIT 1',
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
            'UPDATE inventory_count SET "status" = 0 WHERE "inventoryCountId" = ? AND "companyId" = ? AND "status" = 1',
            [$id, $companyId]
        );

        return (int) $db->Affected_Rows() > 0;
    }

    public function list(string $companyId, ?string $outletId, ?int $status, int $limit, int $offset): array
    {
        $where  = ['"companyId" = ?'];
        $params = [$companyId];

        if ($outletId !== null) {
            $where[]  = '"outletId" = ?';
            $params[] = $outletId;
        }

        if ($status !== null) {
            $where[]  = '"status" = ?';
            $params[] = $status;
        }

        $whereStr = implode(' AND ', $where);

        $totalRow = ncmExecute(
            "SELECT COUNT(*) as total FROM inventory_count WHERE {$whereStr}",
            $params
        );
        $total = (int) ($totalRow['total'] ?? 0);

        $countParams = array_merge($params, [$limit, $offset]);
        $rowsRs = ncmExecute(
            "SELECT ic.\"inventoryCountId\", ic.\"outletId\", ic.\"locationId\", ic.\"status\",
                    ic.\"startedAt\", ic.\"finishedAt\", ic.\"note\",
                    o.\"outletName\",
                    t.\"taxonomyName\" as \"locationName\",
                    (SELECT COUNT(*) FROM inventory_count_item ici WHERE ici.\"inventoryCountId\" = ic.\"inventoryCountId\") as \"totalItems\",
                    (SELECT COUNT(*) FROM inventory_count_item ici WHERE ici.\"inventoryCountId\" = ic.\"inventoryCountId\" AND ici.\"countedQty\" IS NOT NULL) as \"countedItems\",
                    (SELECT COALESCE(SUM(ici.\"difference\" * ici.\"unitCost\"), 0) FROM inventory_count_item ici WHERE ici.\"inventoryCountId\" = ic.\"inventoryCountId\" AND ici.\"countedQty\" IS NOT NULL AND ici.\"difference\" IS NOT NULL) as \"totalCostDelta\"
             FROM inventory_count ic
             JOIN outlet o ON o.\"outletId\" = ic.\"outletId\"
             LEFT JOIN taxonomy t ON t.\"taxonomyId\" = ic.\"locationId\"
             WHERE {$whereStr}
             ORDER BY ic.\"startedAt\" DESC
             LIMIT ? OFFSET ?",
            $countParams,
            false,
            true
        );

        $rows = [];
        if ($rowsRs) {
            while (!$rowsRs->EOF) {
                $r      = $rowsRs->fields;
                $rows[] = [
                    'inventoryCountId' => $r['inventoryCountId'],
                    'outletId'         => $r['outletId'],
                    'outletName'       => $r['outletName'],
                    'locationId'       => $r['locationId'],
                    'locationName'     => $r['locationName'],
                    'status'           => (int) $r['status'],
                    'startedAt'        => $r['startedAt'],
                    'finishedAt'       => $r['finishedAt'],
                    'note'             => $r['note'],
                    'totalItems'       => (int) $r['totalItems'],
                    'countedItems'     => (int) $r['countedItems'],
                    'totalCostDelta'   => (float) $r['totalCostDelta'],
                ];
                $rowsRs->MoveNext();
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
