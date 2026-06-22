<?php
declare(strict_types=1);

namespace Punto\Api\Services;

use Punto\App\Domain\Inventory;

/**
 * Servicio de devoluciones (transactionType = 6).
 *
 * Vincula la devolución a la venta original via transactionParentId.
 * La validación de qty disponible se ejecuta DENTRO de la TX con FOR UPDATE
 * en la fila padre para evitar race conditions entre requests concurrentes.
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
     * @param string|null $registerId  Puede ser null cuando se llama desde panel sin caja.
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
        ?string $registerId,
        string  $parentTransactionId,
        array   $items,
        string  $refundMode,
        ?string $note
    ): array {
        global $db;

        if (empty($items)) {
            throw new \InvalidArgumentException('No hay items para devolver.');
        }

        // Validación rápida pre-TX (solo lookups de solo lectura, sin locks)
        if ($refundMode === 'credit') {
            $parentCheck = ncmExecute(
                'SELECT "customerId" FROM transaction
                 WHERE "transactionId" = ? AND "companyId" = ? AND "transactionType" IN (0, 3)',
                [$parentTransactionId, $companyId]
            );
            if (!$parentCheck) {
                throw new \InvalidArgumentException('Transacción no encontrada o no válida para devolución.');
            }
            if (empty($parentCheck['customerId'])) {
                throw new \InvalidArgumentException('Modo crédito requiere que la venta original tenga cliente asociado.');
            }
        }

        // TX: todo el read-with-lock + writes en un bloque atómico
        $db->StartTrans();

        $newTransactionId = null;
        $stockMovements   = 0;
        $returnTotal      = 0.0;
        $processedItems   = [];

        try {
            // Lock de la fila padre — evita que dos requests concurrentes lean
            // `alreadyReturned` antes de que el primero escriba.
            $parent = $db->GetRow(
                'SELECT "transactionId", "transactionType", "customerId"
                 FROM transaction
                 WHERE "transactionId" = ? AND "companyId" = ? AND "transactionType" IN (0, 3)
                 FOR UPDATE',
                [$parentTransactionId, $companyId]
            );
            if (!$parent) {
                throw new \InvalidArgumentException('Transacción no encontrada o no válida para devolución.');
            }
            if ($refundMode === 'credit' && empty($parent['customerId'])) {
                throw new \InvalidArgumentException('Modo crédito requiere que la venta original tenga cliente asociado.');
            }

            // Procesar cada ítem: leer original + validar qty disponible (dentro de la TX)
            foreach ($items as $item) {
                $itemId = (string) ($item['itemId'] ?? '');
                $reqQty = (float)  ($item['qty']    ?? 0);

                if ($reqQty <= 0) {
                    throw new \InvalidArgumentException("Qty inválida para item $itemId.");
                }

                // Datos del itemSold original — scopeado por companyId via la TX padre
                $origItem = $db->GetRow(
                    'SELECT is1."itemSoldUnits", is1."itemSoldTotal", is1."itemSoldCOGS",
                            i."itemHasStock", i."itemLocationId"
                     FROM "itemSold" is1
                     JOIN item i ON i."itemId" = is1."itemId"
                     WHERE is1."transactionId" = ? AND is1."itemId" = ?',
                    [$parentTransactionId, $itemId]
                );
                if (!$origItem) {
                    throw new \InvalidArgumentException("Item $itemId no encontrado en la transacción original.");
                }

                $soldQty   = abs((float) $origItem['itemSoldUnits']);
                $lineTotal = abs((float) $origItem['itemSoldTotal']);
                $unitPrice = $soldQty > 0 ? $lineTotal / $soldQty : 0.0;
                $cogs      = abs((float) $origItem['itemSoldCOGS']);
                $unitCogs  = $soldQty > 0 ? $cogs / $soldQty : 0.0;

                // Qty ya devuelta (dentro de TX — ve las filas del lock anterior)
                $alreadyReturned = (float) $db->GetOne(
                    'SELECT COALESCE(SUM(ABS(is2."itemSoldUnits")), 0)
                     FROM "itemSold" is2
                     JOIN transaction t ON t."transactionId" = is2."transactionId"
                     WHERE t."transactionParentId" = ? AND t."transactionType" = 6
                       AND t."companyId" = ? AND is2."itemId" = ?',
                    [$parentTransactionId, $companyId, $itemId]
                );

                $available = $soldQty - $alreadyReturned;
                if ($reqQty > $available + 0.001) {
                    throw new \InvalidArgumentException(
                        "Item $itemId: qty solicitada ($reqQty) supera disponible ($available)."
                    );
                }

                $lineReturnTotal = round($unitPrice * $reqQty, 2);
                $lineCogs        = round($unitCogs  * $reqQty, 2);
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

            $newTransactionId = $db->GetOne('SELECT gen_random_uuid()');

            // Pagos: monto negativo = egreso de caja
            $paymentsJson = json_encode([[
                'type'   => $refundMode === 'cash' ? 'cash' : 'storeCredit',
                'amount' => -abs($returnTotal),
            ]]);

            // totalUnits = suma real de unidades devueltas (no cuenta de líneas)
            $totalUnits = -array_sum(array_column($processedItems, 'qty'));

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
                    $registerId ?: null,
                    $userId,
                    $outletId,
                    $companyId,
                ]
            );

            foreach ($processedItems as $pi) {
                $itemSoldId = $db->GetOne('SELECT gen_random_uuid()');

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
                        -abs($pi['qty']),
                        -abs($pi['lineTotal']),
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

            if ($refundMode === 'credit' && !empty($parent['customerId'])) {
                $db->Execute(
                    'UPDATE contact SET "contactStoreCredit" = "contactStoreCredit" + ?
                     WHERE "contactId" = ? AND "companyId" = ?',
                    [abs($returnTotal), $parent['customerId'], $companyId]
                );
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
