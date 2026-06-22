<?php
declare(strict_types=1);
namespace Punto\Api\Services;

/**
 * StockTransferService — transferencia de stock entre outlets/depósitos.
 *
 * Los movimientos se aplican via \Punto\App\Domain\Inventory::manageStock().
 * NUNCA se escribe directamente en la tabla stock.
 *
 * Decisión de cancelación: al revertir una transferencia se permite overdraft
 * en el outlet destino porque el stock puede haber sido consumido (vendido)
 * entre la transferencia y la cancelación. Forzar stock >= 0 bloquearía
 * cancelaciones legítimas — es preferible registrar el saldo negativo y que
 * el operador lo corrija con un ajuste.
 */
final class StockTransferService
{
    public function create(
        string $companyId,
        string $userId,
        array  $from,
        array  $to,
        ?string $note,
        array  $items
    ): array {
        global $db;

        // --- Validaciones pre-TX ---

        // 1. Verificar outlets pertenecen al tenant
        $fromOutlet = ncmExecute(
            'SELECT "outletId" FROM outlet WHERE "outletId" = ? AND "companyId" = ? LIMIT 1',
            [$from['outletId'], $companyId]
        );
        if (!$fromOutlet) {
            throw new \InvalidArgumentException('fromOutletId inválido para este tenant', 422);
        }

        $toOutlet = ncmExecute(
            'SELECT "outletId" FROM outlet WHERE "outletId" = ? AND "companyId" = ? LIMIT 1',
            [$to['outletId'], $companyId]
        );
        if (!$toOutlet) {
            throw new \InvalidArgumentException('toOutletId inválido para este tenant', 422);
        }

        // 2. Origen y destino idénticos
        $fromLocationId = $from['locationId'] ?? null;
        $toLocationId   = $to['locationId']   ?? null;

        if (
            $from['outletId'] === $to['outletId'] &&
            $fromLocationId === $toLocationId
        ) {
            throw new \InvalidArgumentException('El origen y destino son idénticos', 422);
        }

        // 3. Verificar locationIds pertenecen a sus outlets
        if ($fromLocationId !== null) {
            $loc = ncmExecute(
                "SELECT \"taxonomyId\" FROM taxonomy WHERE \"taxonomyId\" = ? AND \"outletId\" = ? AND \"taxonomyType\" = 'location' LIMIT 1",
                [$fromLocationId, $from['outletId']]
            );
            if (!$loc) {
                throw new \InvalidArgumentException('fromLocationId no pertenece al outlet origen', 422);
            }
        }

        if ($toLocationId !== null) {
            $loc = ncmExecute(
                "SELECT \"taxonomyId\" FROM taxonomy WHERE \"taxonomyId\" = ? AND \"outletId\" = ? AND \"taxonomyType\" = 'location' LIMIT 1",
                [$toLocationId, $to['outletId']]
            );
            if (!$loc) {
                throw new \InvalidArgumentException('toLocationId no pertenece al outlet destino', 422);
            }
        }

        // 4. Validar qty > 0 para todos los items
        foreach ($items as $item) {
            if (!isset($item['qty']) || (float) $item['qty'] <= 0) {
                throw new \InvalidArgumentException('Todos los items deben tener qty > 0', 422);
            }
        }

        if (empty($items)) {
            return ['id' => null, 'itemsProcessed' => 0, 'skippedItems' => []];
        }

        // --- Pre-query: filtrar items stockeables ---
        $itemIds      = array_map(fn($i) => $i['itemId'], $items);
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params       = array_merge([$companyId], $itemIds);

        $stockableRs = ncmExecute(
            'SELECT "itemId" FROM item WHERE "companyId" = ? AND "itemStatus" = 1 AND "itemTrackInventory" >= 1 AND "itemId" IN (' . $placeholders . ')',
            $params,
            false,
            true
        );

        $stockableIds = [];
        if ($stockableRs) {
            while (!$stockableRs->EOF) {
                $stockableIds[] = $stockableRs->fields['itemId'];
                $stockableRs->MoveNext();
            }
        }

        $skippedItems    = [];
        $stockableItems  = [];
        foreach ($items as $item) {
            if (in_array($item['itemId'], $stockableIds, true)) {
                $stockableItems[] = $item;
            } else {
                $skippedItems[] = $item['itemId'];
            }
        }

        // --- Obtener snapshot COGS del outlet origen ---
        // unitCost = stockOnHandCOGS del último movimiento de stock en from.outletId.
        // Si no hay stock previo en ese outlet, unitCost = 0 (ingreso sin costo previo).
        $cogsMap = [];
        foreach ($stockableItems as $item) {
            $stockRow = ncmExecute(
                'SELECT "stockOnHandCOGS" FROM stock WHERE "itemId" = ? AND "outletId" = ? ORDER BY "stockDate" DESC, "stockId" DESC LIMIT 1',
                [$item['itemId'], $from['outletId']]
            );
            $cogsMap[$item['itemId']] = $stockRow ? (float) $stockRow['stockOnHandCOGS'] : 0.0;
        }

        // --- TX ---
        $db->StartTrans();

        $headerRow = ncmExecute(
            'INSERT INTO stock_transfer ("companyId", "fromOutletId", "fromLocationId", "toOutletId", "toLocationId", "note", "createdBy")
             VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING "stockTransferId"',
            [
                $companyId,
                $from['outletId'],
                $fromLocationId,
                $to['outletId'],
                $toLocationId,
                $note ?: null,
                $userId,
            ]
        );

        if (!$headerRow || empty($headerRow['stockTransferId'])) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('No se pudo crear la transferencia');
        }

        $transferId = $headerRow['stockTransferId'];

        foreach ($stockableItems as $item) {
            $itemId   = $item['itemId'];
            $qty      = (float) $item['qty'];
            $unitCost = $cogsMap[$itemId] ?? 0.0;

            ncmExecute(
                'INSERT INTO stock_transfer_item ("stockTransferId", "itemId", "qty", "unitCost") VALUES (?, ?, ?, ?)',
                [$transferId, $itemId, $qty, $unitCost]
            );

            // Egreso del outlet origen
            $result = \Punto\App\Domain\Inventory::manageStock([
                'itemId'     => $itemId,
                'source'     => 'transfer',
                'count'      => $qty,
                'type'       => '-',
                'cogs'       => $unitCost,
                'userId'     => $userId,
                'outletId'   => $from['outletId'],
                'locationId' => $fromLocationId,
                'note'       => 'transfer #' . $transferId,
                'date'       => date('Y-m-d H:i:s'),
                'companyId'  => $companyId,
            ]);

            if ($result === false) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException('Error al aplicar egreso para item ' . $itemId);
            }

            // Ingreso en outlet destino
            $result = \Punto\App\Domain\Inventory::manageStock([
                'itemId'     => $itemId,
                'source'     => 'transfer',
                'count'      => $qty,
                'type'       => '+',
                'cogs'       => $unitCost,
                'userId'     => $userId,
                'outletId'   => $to['outletId'],
                'locationId' => $toLocationId,
                'note'       => 'transfer #' . $transferId,
                'date'       => date('Y-m-d H:i:s'),
                'companyId'  => $companyId,
            ]);

            if ($result === false) {
                $db->FailTrans();
                $db->CompleteTrans();
                throw new \RuntimeException('Error al aplicar ingreso para item ' . $itemId);
            }
        }

        $db->CompleteTrans();

        // best-effort realtime
        realtimePublish('stock-transfer', 'create', $transferId);
        realtimePublish('item', 'update', null);

        return [
            'id'             => $transferId,
            'itemsProcessed' => count($stockableItems),
            'skippedItems'   => $skippedItems,
        ];
    }

    public function list(string $companyId, array $filters = []): array
    {
        $where  = ['st."companyId" = ?'];
        $params = [$companyId];

        if (!empty($filters['fromOutletId'])) {
            $where[]  = 'st."fromOutletId" = ?';
            $params[] = $filters['fromOutletId'];
        }
        if (!empty($filters['toOutletId'])) {
            $where[]  = 'st."toOutletId" = ?';
            $params[] = $filters['toOutletId'];
        }
        if (isset($filters['status']) && $filters['status'] !== null) {
            $where[]  = 'st."status" = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['dateFrom'])) {
            $where[]  = 'st."createdAt" >= ?';
            $params[] = $filters['dateFrom'];
        }
        if (!empty($filters['dateTo'])) {
            $where[]  = 'st."createdAt" <= ?';
            $params[] = $filters['dateTo'];
        }

        $whereStr = implode(' AND ', $where);

        $totalRow = ncmExecute(
            'SELECT COUNT(*) as total FROM stock_transfer st WHERE ' . $whereStr,
            $params
        );
        $total = (int) ($totalRow['total'] ?? 0);

        $limit  = max(1, min(200, (int) ($filters['limit']  ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $listParams = array_merge($params, [$limit, $offset]);
        $rowsRs = ncmExecute(
            'SELECT st."stockTransferId", st."status", st."createdAt", st."note",
                    st."fromOutletId", st."fromLocationId",
                    st."toOutletId",   st."toLocationId",
                    fo."outletName" as "fromOutletName",
                    to_."outletName" as "toOutletName",
                    tfl."taxonomyName" as "fromLocationName",
                    ttl."taxonomyName" as "toLocationName",
                    (SELECT COUNT(*) FROM stock_transfer_item sti WHERE sti."stockTransferId" = st."stockTransferId") as "itemsCount"
             FROM stock_transfer st
             JOIN outlet fo  ON fo."outletId"  = st."fromOutletId"
             JOIN outlet to_ ON to_."outletId" = st."toOutletId"
             LEFT JOIN taxonomy tfl ON tfl."taxonomyId" = st."fromLocationId"
             LEFT JOIN taxonomy ttl ON ttl."taxonomyId" = st."toLocationId"
             WHERE ' . $whereStr . '
             ORDER BY st."createdAt" DESC
             LIMIT ? OFFSET ?',
            $listParams,
            false,
            true
        );

        $rows = [];
        if ($rowsRs) {
            while (!$rowsRs->EOF) {
                $r      = $rowsRs->fields;
                $rows[] = [
                    'stockTransferId'  => $r['stockTransferId'],
                    'status'           => (int) $r['status'],
                    'createdAt'        => $r['createdAt'],
                    'note'             => $r['note'],
                    'fromOutletId'     => $r['fromOutletId'],
                    'fromOutletName'   => $r['fromOutletName'],
                    'fromLocationId'   => $r['fromLocationId'],
                    'fromLocationName' => $r['fromLocationName'],
                    'toOutletId'       => $r['toOutletId'],
                    'toOutletName'     => $r['toOutletName'],
                    'toLocationId'     => $r['toLocationId'],
                    'toLocationName'   => $r['toLocationName'],
                    'itemsCount'       => (int) $r['itemsCount'],
                ];
                $rowsRs->MoveNext();
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    public function get(string $id, string $companyId): ?array
    {
        $header = ncmExecute(
            'SELECT st.*,
                    fo."outletName" as "fromOutletName",
                    to_."outletName" as "toOutletName",
                    tfl."taxonomyName" as "fromLocationName",
                    ttl."taxonomyName" as "toLocationName",
                    u."userName" as "createdByName"
             FROM stock_transfer st
             JOIN outlet fo  ON fo."outletId"  = st."fromOutletId"
             JOIN outlet to_ ON to_."outletId" = st."toOutletId"
             LEFT JOIN taxonomy tfl ON tfl."taxonomyId" = st."fromLocationId"
             LEFT JOIN taxonomy ttl ON ttl."taxonomyId" = st."toLocationId"
             LEFT JOIN "user" u ON u."userId" = st."createdBy"
             WHERE st."stockTransferId" = ? AND st."companyId" = ?
             LIMIT 1',
            [$id, $companyId]
        );

        if (!$header) {
            return null;
        }

        $itemsRs = ncmExecute(
            'SELECT sti."stockTransferItemId", sti."itemId", sti."qty", sti."unitCost",
                    i."itemName" as name, i."itemSku" as sku
             FROM stock_transfer_item sti
             JOIN item i ON i."itemId" = sti."itemId"
             JOIN stock_transfer st ON st."stockTransferId" = sti."stockTransferId" AND st."companyId" = ?
             WHERE sti."stockTransferId" = ?
             ORDER BY i."itemName" ASC',
            [$companyId, $id],
            false,
            true
        );

        $lineItems = [];
        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $r           = $itemsRs->fields;
                $lineItems[] = [
                    'stockTransferItemId' => $r['stockTransferItemId'],
                    'itemId'              => $r['itemId'],
                    'name'                => $r['name'],
                    'sku'                 => $r['sku'],
                    'qty'                 => (float) $r['qty'],
                    'unitCost'            => (float) $r['unitCost'],
                ];
                $itemsRs->MoveNext();
            }
        }

        return [
            'transfer' => [
                'stockTransferId'  => $header['stockTransferId'],
                'companyId'        => $header['companyId'],
                'status'           => (int) $header['status'],
                'createdAt'        => $header['createdAt'],
                'note'             => $header['note'],
                'fromOutletId'     => $header['fromOutletId'],
                'fromOutletName'   => $header['fromOutletName'],
                'fromLocationId'   => $header['fromLocationId'],
                'fromLocationName' => $header['fromLocationName'],
                'toOutletId'       => $header['toOutletId'],
                'toOutletName'     => $header['toOutletName'],
                'toLocationId'     => $header['toLocationId'],
                'toLocationName'   => $header['toLocationName'],
                'createdBy'        => $header['createdBy'],
                'createdByName'    => $header['createdByName'],
            ],
            'items' => $lineItems,
        ];
    }

    public function cancel(string $id, string $companyId, string $userId): array
    {
        global $db;

        $db->StartTrans();

        $header = ncmExecute(
            'SELECT * FROM stock_transfer WHERE "stockTransferId" = ? AND "companyId" = ? FOR UPDATE',
            [$id, $companyId]
        );

        if (!$header) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \InvalidArgumentException('Transferencia no encontrada', 404);
        }

        if ((int) $header['status'] !== 1) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw new \RuntimeException('La transferencia ya fue cancelada', 409);
        }

        $itemsRs = ncmExecute(
            'SELECT "itemId", "qty", "unitCost" FROM stock_transfer_item WHERE "stockTransferId" = ?',
            [$id],
            false,
            true
        );

        if ($itemsRs) {
            while (!$itemsRs->EOF) {
                $row      = $itemsRs->fields;
                $itemId   = $row['itemId'];
                $qty      = (float) $row['qty'];
                $unitCost = (float) $row['unitCost'];

                // Reversa: egreso en destino (puede quedar negativo si el stock ya fue vendido — permitido)
                $result = \Punto\App\Domain\Inventory::manageStock([
                    'itemId'     => $itemId,
                    'source'     => 'transfer-cancel',
                    'count'      => $qty,
                    'type'       => '-',
                    'cogs'       => $unitCost,
                    'userId'     => $userId,
                    'outletId'   => $header['toOutletId'],
                    'locationId' => $header['toLocationId'],
                    'note'       => 'cancel transfer #' . $id,
                    'date'       => date('Y-m-d H:i:s'),
                    'companyId'  => $companyId,
                ]);
                if ($result === false) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    throw new \RuntimeException("Error al revertir egreso en destino para item {$itemId}");
                }

                // Reversa: ingreso en origen
                $result = \Punto\App\Domain\Inventory::manageStock([
                    'itemId'     => $itemId,
                    'source'     => 'transfer-cancel',
                    'count'      => $qty,
                    'type'       => '+',
                    'cogs'       => $unitCost,
                    'userId'     => $userId,
                    'outletId'   => $header['fromOutletId'],
                    'locationId' => $header['fromLocationId'],
                    'note'       => 'cancel transfer #' . $id,
                    'date'       => date('Y-m-d H:i:s'),
                    'companyId'  => $companyId,
                ]);
                if ($result === false) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    throw new \RuntimeException("Error al revertir ingreso en origen para item {$itemId}");
                }

                $itemsRs->MoveNext();
            }
        }

        $db->Execute(
            'UPDATE stock_transfer SET "status" = 0 WHERE "stockTransferId" = ? AND "companyId" = ?',
            [$id, $companyId]
        );

        $db->CompleteTrans();

        realtimePublish('stock-transfer', 'cancel', $id);
        realtimePublish('item', 'update', null);

        return ['ok' => true];
    }
}
