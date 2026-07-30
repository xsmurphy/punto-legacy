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
                'SELECT customerid FROM transaction
                 WHERE transactionid = ? AND companyid = ? AND transactiontype IN (0, 3)',
                [$parentTransactionId, $companyId]
            );
            if (!$parentCheck) {
                throw new \InvalidArgumentException('Transacción no encontrada o no válida para devolución.');
            }
            if (empty($parentCheck['customerid'])) {
                throw new \InvalidArgumentException('Modo crédito requiere que la venta original tenga cliente asociado.');
            }
        }

        // TX: todo el read-with-lock + writes en un bloque atómico
        $db->StartTrans();

        $newTransactionId = null;
        $stockMovements   = 0;
        $returnTotal      = 0.0;   // bruto devuelto (espeja itemSoldTotal)
        $returnDiscount   = 0.0;   // descuento proporcional devuelto
        $processedItems   = [];

        try {
            // Lock de la fila padre — evita que dos requests concurrentes lean
            // `alreadyReturned` antes de que el primero escriba.
            $parent = $db->GetRow(
                'SELECT transactionid, transactiontype, customerid
                 FROM transaction
                 WHERE transactionid = ? AND companyid = ? AND transactiontype IN (0, 3)
                 FOR UPDATE',
                [$parentTransactionId, $companyId]
            );
            if (!$parent) {
                throw new \InvalidArgumentException('Transacción no encontrada o no válida para devolución.');
            }
            if ($refundMode === 'credit' && empty($parent['customerid'])) {
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
                    'SELECT is1.itemsoldunits, is1.itemsoldtotal, is1.itemsolddiscount,
                            is1.itemsoldcogs, i.itemhasstock, i.itemlocationid
                     FROM "itemSold" is1
                     JOIN item i ON i.itemid = is1.itemid
                     WHERE is1.transactionid = ? AND is1.itemid = ?',
                    [$parentTransactionId, $itemId]
                );
                if (!$origItem) {
                    throw new \InvalidArgumentException("Item $itemId no encontrado en la transacción original.");
                }

                $soldQty   = abs((float) $origItem['itemsoldunits']);
                // itemSoldTotal es el BRUTO de la linea y itemSoldDiscount su
                // descuento (ver lib/cart/allocate-discounts.ts): lo que el
                // cliente pago es la resta. Devolver sobre el bruto le estaria
                // reintegrando plata que nunca entrego.
                $lineTotal = abs((float) $origItem['itemsoldtotal']);
                $lineDisc  = abs((float) ($origItem['itemsolddiscount'] ?? 0));
                $unitPrice = $soldQty > 0 ? $lineTotal / $soldQty : 0.0;
                $unitDisc  = $soldQty > 0 ? $lineDisc  / $soldQty : 0.0;
                $cogs      = abs((float) $origItem['itemsoldcogs']);
                $unitCogs  = $soldQty > 0 ? $cogs / $soldQty : 0.0;

                // Qty ya devuelta (dentro de TX — ve las filas del lock anterior)
                $alreadyReturned = (float) $db->GetOne(
                    'SELECT COALESCE(SUM(ABS(is2.itemsoldunits)), 0)
                     FROM "itemSold" is2
                     JOIN transaction t ON t.transactionid = is2.transactionid
                     WHERE t.transactionparentid = ? AND t.transactiontype = 6
                       AND t.companyid = ? AND is2.itemid = ?',
                    [$parentTransactionId, $companyId, $itemId]
                );

                $available = $soldQty - $alreadyReturned;
                if ($reqQty > $available + 0.001) {
                    throw new \InvalidArgumentException(
                        "Item $itemId: qty solicitada ($reqQty) supera disponible ($available)."
                    );
                }

                // Se espeja el shape de la venta: el bruto y el descuento viajan
                // por separado, ambos en negativo. Asi una devolucion total
                // cancela EXACTAMENTE a la venta original en las dos columnas.
                $lineReturnTotal = round($unitPrice * $reqQty, 2);
                $lineReturnDisc  = round($unitDisc  * $reqQty, 2);
                $lineCogs        = round($unitCogs  * $reqQty, 2);
                $returnTotal    += $lineReturnTotal;
                $returnDiscount += $lineReturnDisc;

                $processedItems[] = [
                    'itemId'      => $itemId,
                    'qty'         => $reqQty,
                    'lineTotal'   => $lineReturnTotal,
                    'lineDiscount'=> $lineReturnDisc,
                    'cogs'        => $lineCogs,
                    'hasStock'   => !empty($origItem['itemhasstock']),
                    'locationId' => $origItem['itemlocationid'] ?? null,
                ];
            }

            $newTransactionId = $db->GetOne('SELECT gen_random_uuid()');

            // Lo que efectivamente sale de la caja (o se acredita) es el NETO:
            // el bruto menos el descuento que el cliente ya no pago en su momento.
            $returnNet = round($returnTotal - $returnDiscount, 2);

            // Pagos: monto negativo = egreso de caja
            $paymentsJson = json_encode([[
                'type'   => $refundMode === 'cash' ? 'cash' : 'storeCredit',
                'amount' => -abs($returnNet),
            ]]);

            // totalUnits = suma real de unidades devueltas (no cuenta de líneas)
            $totalUnits = -array_sum(array_column($processedItems, 'qty'));

            $db->Execute(
                'INSERT INTO transaction (
                    transactionid, transactiontype, transactionparentid,
                    transactiontotal, transactiondiscount, transactionunitssold,
                    transactionpaymenttype,
                    transactiondate, transactionnote, transactionstatus, transactioncomplete,
                    customerid, registerid, userid, outletid, companyid, meta
                ) VALUES (?, 6, ?, ?, ?, ?, ?, NOW(), ?, 1, TRUE, ?, ?, ?, ?, ?, \'{}\')',
                [
                    $newTransactionId,
                    $parentTransactionId,
                    -abs($returnTotal),
                    -abs($returnDiscount),
                    $totalUnits,
                    $paymentsJson,
                    $note,
                    $parent['customerid'] ?? null,
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
                        itemsoldid, itemid, transactionid,
                        itemsoldunits, itemsoldtotal, itemsolddiscount, itemsoldcogs,
                        itemsolddate
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        $itemSoldId,
                        $pi['itemId'],
                        $newTransactionId,
                        -abs($pi['qty']),
                        -abs($pi['lineTotal']),
                        -abs($pi['lineDiscount']),
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

            if ($refundMode === 'credit' && !empty($parent['customerid'])) {
                $db->Execute(
                    'UPDATE contact SET contactstorecredit = contactstorecredit + ?
                     WHERE contactid = ? AND companyid = ?',
                    [abs($returnNet), $parent['customerid'], $companyId]
                );
            }

            // Nota de crédito (F3): se encola DENTRO de la transacción, igual
            // que la factura de una venta (SaleService::enqueueElectronicInvoice)
            // — si la devolución se revierte, no queda un documento fiscal
            // encolado para una devolución que nunca existió. El servicio es
            // silencioso puertas adentro (sin cuenta conectada, autoIssue off);
            // el try/catch es para que un bug del módulo de FE no tumbe una
            // devolución ya validada.
            try {
                (new \Punto\Api\EInvoice\EInvoiceService())->enqueueForSale(
                    $companyId,
                    $newTransactionId,
                    'NC',
                    $parent['customerid'] ?? null,
                );
            } catch (\Throwable $e) {
                error_log('[ReturnService] enqueue nota de crédito: ' . $e->getMessage());
            }

            $db->CompleteTrans();

        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        // Emisión inline POST-COMMIT, best-effort: la devolución ya está
        // confirmada y un fallo acá solo deja el documento pendiente para el
        // drainer (mismo criterio que SaleService::tryIssueElectronicInvoiceInline).
        try {
            (new \Punto\Api\EInvoice\EInvoiceService())->tryIssueInline($companyId, (string) $newTransactionId, 'NC');
        } catch (\Throwable $e) {
            error_log('[ReturnService] emisión inline de la nota de crédito: ' . $e->getMessage());
        }

        return [
            'id'                    => $newTransactionId,
            'total'                 => abs($returnNet),
            'refundMode'            => $refundMode,
            'stockMovements'        => $stockMovements,
            'customerCreditApplied' => $refundMode === 'credit' ? abs($returnNet) : null,
        ];
    }

    /**
     * Lista todas las devoluciones de una venta original.
     */
    public function listForParent(string $parentTransactionId, string $companyId): array
    {
        $rs = ncmExecute(
            'SELECT t.transactionid, t.transactiontotal, t.transactiondate,
                    t.transactionnote, t.transactionpaymenttype
             FROM transaction t
             WHERE t.transactionparentid = ? AND t.companyid = ? AND t.transactiontype = 6
             ORDER BY t.transactiondate DESC',
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
