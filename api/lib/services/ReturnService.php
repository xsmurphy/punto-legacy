<?php
declare(strict_types=1);

namespace Punto\Api\Services;

use Punto\App\Domain\Inventory;

/**
 * Servicio de devoluciones (transactionType = 6).
 *
 * Vincula la devolución a la venta original via transactionParentId.
 * Valida qty disponible (vendida − ya devuelta) por ítem.
 * Maneja stock (reposición) y crédito al cliente según refundMode.
 */
final class ReturnService
{
    /**
     * Crea una devolución vinculada a una venta original.
     *
     * @param string      $companyId
     * @param string      $userId
     * @param string      $outletId
     * @param string      $registerId
     * @param string      $parentTransactionId
     * @param array       $items    [{itemId: string, qty: float}]
     * @param string      $refundMode  'cash' | 'credit'
     * @param string|null $note
     * @return array
     */
    public function create(
        string  $companyId,
        string  $userId,
        string  $outletId,
        string  $registerId,
        string  $parentTransactionId,
        array   $items,
        string  $refundMode,
        ?string $note
    ): array {
        global $db;

        // 1. Cargar la transacción parent — solo ventas (type 0 o 3)
        $parent = ncmExecute(
            'SELECT "transactionId", "transactionType", "customerId", "outletId", "companyId"
             FROM transaction
             WHERE "transactionId" = ? AND "companyId" = ? AND "transactionType" IN (0, 3)',
            [$parentTransactionId, $companyId]
        );
        if (!$parent) {
            throw new \InvalidArgumentException('Transacción no encontrada o no válida para devolución.');
        }

        // 2. Crédito requiere cliente
        if ($refundMode === 'credit' && empty($parent['customerId'])) {
            throw new \InvalidArgumentException('Modo crédito requiere que la venta original tenga cliente asociado.');
        }

        // 3. Procesar items — calcular totales y validar qty
        $processedItems = [];
        $returnTotal    = 0.0;

        foreach ($items as $item) {
            $itemId = (string) ($item['itemId'] ?? '');
            $reqQty = (float)  ($item['qty']    ?? 0);

            if ($reqQty <= 0) {
                throw new \InvalidArgumentException("Qty inválida para item $itemId.");
            }

            // Datos del itemSold original
            $origItem = ncmExecute(
                'SELECT is1."itemSoldId", is1."itemSoldUnits", is1."itemSoldTotal", is1."itemSoldCOGS",
                        i."itemHasStock", i."itemLocationId"
                 FROM "itemSold" is1
                 JOIN item i ON i."itemId" = is1."itemId"
                 WHERE is1."transactionId" = ? AND is1."itemId" = ? AND is1."transactionId" IN (
                     SELECT "transactionId" FROM transaction WHERE "transactionId" = ? AND "companyId" = ?
                 )',
                [$parentTransactionId, $itemId, $parentTransactionId, $companyId]
            );
            if (!$origItem) {
                throw new \InvalidArgumentException("Item $itemId no encontrado en la transacción original.");
            }

            $soldQty   = abs((float) $origItem['itemSoldUnits']);
            $lineTotal = abs((float) $origItem['itemSoldTotal']);
            $unitPrice = $soldQty > 0 ? $lineTotal / $soldQty : 0.0;
            $cogs      = (float) $origItem['itemSoldCOGS'];
            $unitCogs  = $soldQty > 0 ? $cogs / $soldQty : 0.0;

            // Qty ya devuelta previamente para este item en esta transacción
            $alreadyReturnedRow = ncmExecute(
                'SELECT COALESCE(SUM(ABS(is2."itemSoldUnits")), 0) AS already_returned
                 FROM "itemSold" is2
                 JOIN transaction t ON t."transactionId" = is2."transactionId"
                 WHERE t."transactionParentId" = ? AND t."transactionType" = 6
                   AND t."companyId" = ? AND is2."itemId" = ?',
                [$parentTransactionId, $companyId, $itemId]
            );
            $alreadyReturned = (float) ($alreadyReturnedRow['already_returned'] ?? 0);

            $available = $soldQty - $alreadyReturned;
            if ($reqQty > $available + 0.001) {  // 0.001 de tolerancia flotante
                throw new \InvalidArgumentException(
                    "Item $itemId: qty solicitada ($reqQty) supera disponible para devolver ($available)."
                );
            }

            $lineReturnTotal = round($unitPrice * $reqQty, 2);
            $lineCogs        = round(abs($unitCogs) * $reqQty, 2);
            $returnTotal    += $lineReturnTotal;

            $processedItems[] = [
                'itemId'     => $itemId,
                'qty'        => $reqQty,
                'lineTotal'  => $lineReturnTotal,
                'cogs'       => $lineCogs,
                'hasStock'   => !empty($origItem['itemHasStock']),
                'locationId' => $origItem['itemLocationId'] ?? null,
            ];
        }

        if (empty($processedItems)) {
            throw new \InvalidArgumentException('No hay items para devolver.');
        }

        // 4. Transacción de base de datos
        $db->StartTrans();

        try {
            $newTransactionId = ncmExecute('SELECT gen_random_uuid() AS id', []);
            $newTransactionId = $newTransactionId['id'];

            // Pagos: monto negativo = egreso de caja (devolución)
            $paymentsJson = json_encode([[
                'type'   => $refundMode === 'cash' ? 'cash' : 'storeCredit',
                'amount' => -abs($returnTotal),
            ]]);

            $totalUnits = -count($processedItems);

            $db->Execute(
                'INSERT INTO transaction (
                    "transactionId", "transactionType", "transactionParentId",
                    "transactionTotal", "transactionUnitsSold", "transactionPaymentType",
                    "transactionDate", "transactionNote", "transactionStatus", "transactionComplete",
                    "customerId", "registerId", "userId", "outletId", "companyId", meta
                ) VALUES (?, 6, ?, ?, ?, ?, NOW(), ?, 1, TRUE, ?, ?, ?, ?, ?, \'{}\')',
                [
                    $newTransactionId,
                    $parentTransactionId,
                    -abs($returnTotal),
                    $totalUnits,
                    $paymentsJson,
                    $note,
                    $parent['customerId'] ?? null,
                    $registerId,
                    $userId,
                    $outletId,
                    $companyId,
                ]
            );

            $stockMovements = 0;

            foreach ($processedItems as $pi) {
                $itemSoldId = ncmExecute('SELECT gen_random_uuid() AS id', []);
                $itemSoldId = $itemSoldId['id'];

                $db->Execute(
                    'INSERT INTO "itemSold" (
                        "itemSoldId", "itemId", "transactionId",
                        "itemSoldUnits", "itemSoldTotal", "itemSoldCOGS",
                        "itemSoldDate"
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $itemSoldId,
                        $pi['itemId'],
                        $newTransactionId,
                        -abs($pi['qty']),       // negativo: unidades que vuelven
                        -abs($pi['lineTotal']), // negativo: ingreso de devolución
                        -abs($pi['cogs']),
                    ]
                );

                if ($pi['hasStock']) {
                    $result = \Punto\App\Domain\Inventory::manageStock([
                        'itemId'        => $pi['itemId'],
                        'outletId'      => $outletId,
                        'date'          => date('Y-m-d'),
                        'locationId'    => $pi['locationId'],
                        'count'         => $pi['qty'],
                        'type'          => '+',
                        'source'        => 'return',
                        'transactionId' => $newTransactionId,
                        'cogs'          => $pi['cogs'],
                        'userId'        => $userId,
                        'companyId'     => $companyId,
                    ]);
                    if ($result !== false) {
                        $stockMovements++;
                    }
                }
            }

            // Crédito al cliente
            if ($refundMode === 'credit' && !empty($parent['customerId'])) {
                $db->Execute(
                    'UPDATE contact SET "contactStoreCredit" = "contactStoreCredit" + ? WHERE "contactId" = ? AND "companyId" = ?',
                    [abs($returnTotal), $parent['customerId'], $companyId]
                );
            }

            if ($db->HasFailedTrans()) {
                $db->CompleteTrans();
                throw new \RuntimeException('Error en la transacción de base de datos.');
            }

            $db->CompleteTrans();

        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        return [
            'id'                    => $newTransactionId,
            'total'                 => abs($returnTotal),
            'refundMode'            => $refundMode,
            'stockMovements'        => $stockMovements,
            'customerCreditApplied' => $refundMode === 'credit' ? abs($returnTotal) : null,
        ];
    }

    /**
     * Lista todas las devoluciones de una venta original.
     */
    public function listForParent(string $parentTransactionId, string $companyId): array
    {
        $rs = ncmExecute(
            'SELECT t."transactionId", t."transactionTotal", t."transactionDate",
                    t."transactionNote", t."transactionPaymentType"
             FROM transaction t
             WHERE t."transactionParentId" = ? AND t."companyId" = ? AND t."transactionType" = 6
             ORDER BY t."transactionDate" DESC',
            [$parentTransactionId, $companyId],
            false,
            true
        );

        $rows = [];
        if ($rs) {
            while (!$rs->EOF) {
                $rows[] = $rs->fields;
                $rs->MoveNext();
            }
        }
        return $rows;
    }
}
