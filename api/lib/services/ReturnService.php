<?php
declare(strict_types=1);

namespace Punto\Api\Services;

use Punto\App\Domain\Inventory;

/**
 * Servicio de devoluciones (transactionType = 6).
 *
 * Vincula la devolución a la venta original via transaction_link
 * kind='return' (mig 115, TransactionLinkService).
 * La validación de qty disponible se ejecuta DENTRO de la TX con FOR UPDATE
 * en la fila padre para evitar race conditions entre requests concurrentes.
 * Maneja stock (reposición) y crédito al cliente según refundMode.
 */
final class ReturnService
{
    private ?TransactionLinkService $linkSvc = null;

    private function links(): TransactionLinkService
    {
        return $this->linkSvc ??= new TransactionLinkService();
    }

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
                $returnIds = $this->links()->listDerivedIds($companyId, $parentTransactionId, 'return');
                $alreadyReturned = 0.0;
                if ($returnIds !== []) {
                    $ph = implode(',', array_fill(0, count($returnIds), '?'));
                    $alreadyReturned = (float) $db->GetOne(
                        "SELECT COALESCE(SUM(ABS(is2.itemsoldunits)), 0)
                         FROM \"itemSold\" is2
                         WHERE is2.transactionid IN ($ph) AND is2.itemid = ?",
                        [...$returnIds, $itemId]
                    );
                }

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

            // Pagos: monto negativo = egreso de caja. La clave del monto es
            // `total` — es la que lee TODO el resto del sistema (rollup de
            // payments mig 42, FinanceLedger, EInvoiceService, reportes). Con
            // `amount` la línea se leía como 0 y se descartaba en silencio.
            $paymentsJson = json_encode([[
                'type'  => $refundMode === 'cash' ? 'cash' : 'storeCredit',
                'total' => -abs($returnNet),
            ]]);

            // totalUnits = suma real de unidades devueltas (no cuenta de líneas)
            $totalUnits = -array_sum(array_column($processedItems, 'qty'));

            $db->Execute(
                'INSERT INTO transaction (
                    transactionid, transactiontype,
                    transactiontotal, transactiondiscount, transactionunitssold,
                    transactionpaymenttype,
                    transactiondate, transactionnote, transactionstatus, transactioncomplete,
                    customerid, registerid, userid, outletid, companyid, meta
                ) VALUES (?, 6, ?, ?, ?, ?, NOW(), ?, 1, TRUE, ?, ?, ?, ?, ?, \'{}\')',
                [
                    $newTransactionId,
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
            // Vínculo devolución → venta original (mig 115, kind='return').
            // Dentro de la misma TX: si el commit falla, el link tampoco queda.
            $this->links()->link($companyId, $parentTransactionId, (string) $newTransactionId, 'return');

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

        // Finanzas Fase 3: la devolución saca plata de la caja, así que genera
        // su movimiento derivado igual que una venta genera el suyo
        // (SaleService → recordSale). Post-commit y best-effort: un fallo del
        // ledger nunca rompe una devolución ya confirmada. `refundMode='credit'`
        // no mueve caja — lo filtra FinanceLedger por el medio de pago.
        try {
            (new \Punto\Api\Finance\FinanceLedger())->recordReturn($companyId, (string) $newTransactionId);
        } catch (\Throwable $e) {
            error_log('[ReturnService] FinanceLedger::recordReturn falló para ' . $newTransactionId . ': ' . $e->getMessage());
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
        $returnIds = $this->links()->listDerivedIds($companyId, $parentTransactionId, 'return');
        if ($returnIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($returnIds), '?'));
        $rs = ncmExecute(
            "SELECT t.transactionid, t.transactiontotal, t.transactiondate,
                    t.transactionnote, t.transactionpaymenttype
             FROM transaction t
             WHERE t.transactionid IN ($ph) AND t.companyid = ? AND t.transactiontype = 6
             ORDER BY t.transactiondate DESC",
            [...$returnIds, $companyId],
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
