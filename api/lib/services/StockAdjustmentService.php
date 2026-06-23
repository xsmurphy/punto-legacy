<?php
declare(strict_types=1);
namespace Punto\Api\Services;

final class StockAdjustmentService
{
    public function create(
        string $companyId,
        string $userId,
        string $outletId,
        ?string $locationId,
        string $reason,
        array $items
    ): array {
        global $db;

        // Validar outlet pertenece al tenant
        $outlet = ncmExecute(
            'SELECT outletid FROM outlet WHERE outletid = ? AND companyid = ? LIMIT 1',
            [$outletId, $companyId]
        );
        if (!$outlet) {
            throw new \InvalidArgumentException('outletId inválido para este tenant');
        }

        // Pre-query: qué items son stockeables para este companyId
        $itemIds = array_map(fn($i) => $i['itemId'], $items);

        if (empty($itemIds)) {
            return ['adjustmentsCount' => 0, 'skippedItems' => [], 'totalCostDelta' => 0.0];
        }

        // Construir query con placeholders para el IN
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params = array_merge([$companyId], $itemIds);
        $stockableRs = ncmExecute(
            "SELECT itemid FROM item WHERE companyid = ? AND itemstatus = 1 AND itemtrackinventory >= 1 AND itemid IN ({$placeholders})",
            $params,
            false,
            true
        );

        $stockableIds = [];
        if ($stockableRs) {
            while (!$stockableRs->EOF) {
                $stockableIds[] = $stockableRs->fields['itemid'];
                $stockableRs->MoveNext();
            }
        }

        $skipped = [];
        $toProcess = [];
        foreach ($items as $item) {
            if (in_array($item['itemId'], $stockableIds, true)) {
                $toProcess[] = $item;
            } else {
                $skipped[] = $item['itemId'];
            }
        }

        $adjustmentsCount = 0;
        $totalCostDelta   = 0.0;

        if (!empty($toProcess)) {
            $db->StartTrans();

            foreach ($toProcess as $item) {
                $result = \Punto\App\Domain\Inventory::manageStock([
                    'itemId'     => $item['itemId'],
                    'source'     => 'adjustment',
                    'count'      => $item['qty'],
                    'type'       => $item['type'],
                    'cogs'       => $item['unitCost'] ?? '',
                    'userId'     => $userId,
                    'outletId'   => $outletId,
                    'locationId' => $locationId,
                    'note'       => $reason . (!empty($item['note']) ? ' — ' . $item['note'] : ''),
                    'date'       => date('Y-m-d H:i:s'),
                    'companyId'  => $companyId,
                ]);

                if ($result === false) {
                    $db->FailTrans();
                    $db->CompleteTrans();
                    throw new \RuntimeException('Error al aplicar ajuste para item ' . $item['itemId']);
                }

                $unitCost = isset($item['unitCost']) && is_numeric($item['unitCost']) ? (float) $item['unitCost'] : 0.0;
                $delta    = (float) $item['qty'] * $unitCost * ($item['type'] === '+' ? 1 : -1);
                $totalCostDelta   += $delta;
                $adjustmentsCount++;
            }

            $db->CompleteTrans();
        }

        // best-effort — solo si hubo cambios efectivos
        if ($adjustmentsCount > 0) {
            realtimePublish('item', 'update', null);
        }

        return [
            'adjustmentsCount' => $adjustmentsCount,
            'skippedItems'     => $skipped,
            'totalCostDelta'   => $totalCostDelta,
        ];
    }
}
