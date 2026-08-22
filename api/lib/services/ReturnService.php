<?php
declare(strict_types=1);

namespace Punto\Api\Services;

use Punto\Api\Documents\DocumentNumber;

/**
 * Servicio de devoluciones (transactionType = 6).
 *
 * Vincula la devolución a la venta original via transaction_link
 * kind='return' (mig 115, TransactionLinkService).
 * La validación de qty disponible se ejecuta DENTRO de la TX con FOR UPDATE
 * en la fila padre para evitar race conditions entre requests concurrentes.
 * Maneja stock (reposición) y crédito al cliente según refundMode.
 *
 * Correlativo (context/37, context/40): la devolución de venta es,
 * conceptualmente, la nota de crédito de venta (F3/F4 de context/40, sin
 * implementar como documento propio — acá solo se le da numeración a la
 * transacción type=6 que YA se emite). docType 'nota_credito', scope OUTLET
 * y NO register: a diferencia de la venta, este endpoint acepta llamadas
 * desde 'panel' sin caja abierta (`returns.php`: "`$registerId` null cuando
 * se llama desde panel sin caja abierta"), así que `registerId` no es
 * confiable — mismo motivo por el que `remision` (context/42-remision.md)
 * terminó en scope outlet en vez del `register` que D2 de context/37
 * proponía por default. `outletId` sí es obligatorio en el endpoint (403 si
 * falta), por eso es scope seguro acá.
 *
 * D2 (reposición de stock, 2026-08-21) — antes esta clase reponía SIEMPRE
 * que el ítem tuviera `itemTrackInventory`, sin preguntar y sin registrar
 * merma; eso violaba D2 (una producción directa/combo no se "des-prepara") y
 * no daba lugar a la decisión del cajero. Ahora usa `StockReversalPolicy`
 * (extraída de `SaleVoidService`, mismo wrapper compartido — regla del
 * proyecto de atacar el wrapper, no duplicar el call-site): clasifica cada
 * ítem devuelto contra la tabla D2, acepta `restock`/`itemSoldId` opcionales
 * por línea del request, repone solo lo que el cajero eligió Y el sistema
 * habilita (`canRestock`), y genera `waste_event` para el resto con impacto
 * real de stock.
 *
 * D3 (forma de reintegro, 2026-08-21) — `settingReturnRefund` (`cash` |
 * `credit` | `ask`, default `ask`, `company.config` JSONB) fija una política
 * única de comercio. Con `cash`/`credit` fijado, un request con el otro modo
 * se rechaza (422). Regla ya vigente que se preserva: modo `credit` exige
 * cliente en la venta original — si la política lo fuerza a `credit` y la
 * venta no tiene cliente, cae a `cash` en vez de fallar (no hay a quién
 * acreditarle, pero el comercio igual quiere poder devolver la plata).
 */
final class ReturnService
{
    private ?TransactionLinkService $linkSvc = null;
    private ?StockReversalPolicy    $stockPolicy = null;

    private function links(): TransactionLinkService
    {
        return $this->linkSvc ??= new TransactionLinkService();
    }

    /** D2 — wrapper COMPARTIDO con `SaleVoidService::void()`. Ver StockReversalPolicy. */
    private function stockPolicy(): StockReversalPolicy
    {
        return $this->stockPolicy ??= new StockReversalPolicy();
    }

    /**
     * D3: política de reintegro del tenant. `company.config` es JSONB
     * schemaless — sin DDL propia, mismo patrón que `settingSellSoldOut`/
     * `settingReturnAllowIngredientReversal`.
     */
    private function settingReturnRefund(string $companyId): string
    {
        $row = ncmExecute(
            "SELECT config->>'settingReturnRefund' AS val FROM company WHERE companyid = ? LIMIT 1",
            [$companyId]
        );
        $val = ($row !== false && $row !== null) ? (string) ($row['val'] ?? '') : '';
        return in_array($val, ['cash', 'credit'], true) ? $val : 'ask';
    }

    /**
     * Todas las líneas vendidas de la venta original, AGREGADAS por itemId
     * (una misma venta puede tener el mismo producto en varias filas de
     * `itemSold` — carrito sin `mergeRepeated`, ver comentario histórico más
     * abajo). Reusada por `create()` (validación + armado de la devolución) y
     * `returnOptions()` (listado para la UI) — antes cada una tenía su propia
     * query, ahora es una sola fuente.
     *
     * itemSold SIN comillas a propósito — la tabla se creó sin quotes
     * (db-schema-postgres.sql) → Postgres la plegó a minúsculas (itemsold).
     * Citarla como "itemSold" exige match exacto de mayúsculas y Postgres la
     * rechaza con "relation itemSold does not exist" (mismo bug ya
     * documentado en `EInvoiceService.php:1767`, sigue roto ahí, fuera de
     * alcance).
     *
     * @return array<string,array> itemId => fila agregada (itemsoldid
     *         representativo — MIN, no identifica una fila física puntual
     *         cuando hay 2+ filas del mismo itemId; la clasificación D2 y el
     *         cupo disponible son itemId-level, no dependen de cuál fila
     *         física es).
     */
    private function aggregatedParentLines(string $parentTransactionId): array
    {
        $rs = ncmExecute(
            'SELECT is1.itemid                              AS itemid,
                    SUM(is1.itemsoldunits)                   AS itemsoldunits,
                    SUM(is1.itemsoldtotal)                   AS itemsoldtotal,
                    SUM(COALESCE(is1.itemsolddiscount, 0))   AS itemsolddiscount,
                    SUM(COALESCE(is1.itemsoldcogs, 0) * ABS(is1.itemsoldunits))
                      / NULLIF(SUM(ABS(is1.itemsoldunits)), 0) AS itemsoldcogs,
                    MIN(is1.itemsoldid::text)                AS itemsoldid,
                    MIN(i.itemname)                          AS itemname
               FROM itemSold is1
               JOIN item i ON i.itemid = is1.itemid
              WHERE is1.transactionid = ?
              GROUP BY is1.itemid',
            [$parentTransactionId],
            false,
            true
        );
        $rows = [];
        if ($rs) {
            while (!$rs->EOF) {
                $rows[(string) $rs->fields['itemid']] = $rs->fields;
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $rows;
    }

    /**
     * Cuánto se devolvió ya de cada itemId de la venta original, sumando
     * TODAS las devoluciones previas vinculadas (`$returnIds`). Reusada por
     * `create()` (guard de cupo) y `returnOptions()` (`alreadyReturned` de
     * cada línea) — antes `create()` la calculaba con una query POR ítem
     * solicitado; ahora es una sola query para todos.
     *
     * @param list<string> $returnIds
     * @return array<string,float> itemId => qty ya devuelta
     */
    private function alreadyReturnedByItem(string $companyId, array $returnIds): array
    {
        if ($returnIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($returnIds), '?'));
        $rs = ncmExecute(
            "SELECT itemid, SUM(ABS(itemsoldunits)) AS qty
               FROM itemSold
              WHERE transactionid IN ($ph)
              GROUP BY itemid",
            $returnIds,
            false,
            true
        );
        $out = [];
        if ($rs) {
            while (!$rs->EOF) {
                $out[(string) $rs->fields['itemid']] = (float) $rs->fields['qty'];
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    /**
     * Por cada línea vendida de la venta original (agregada por itemId): qué
     * es POSIBLE reponer (tabla D2 de context/40), cuánto ya se devolvió y
     * cuánto queda disponible. La UI arma el formulario de devolución con
     * esto en vez de listar los ítems por su cuenta — reemplaza el listado
     * ad-hoc que tenía antes.
     *
     * @return list<array{itemSoldId:string,itemId:string,name:string,soldQty:float,alreadyReturned:float,availableQty:float,unitPrice:float,canRestock:bool,defaultRestock:bool,kind:string}>
     */
    public function returnOptions(string $companyId, string $parentTransactionId): array
    {
        $parent = ncmExecute(
            'SELECT transactionid FROM transaction WHERE transactionid = ? AND companyid = ? AND transactiontype IN (0, 3) LIMIT 1',
            [$parentTransactionId, $companyId]
        );
        if (!$parent) {
            return [];
        }

        $policy = $this->stockPolicy();
        $allowIngredientReversal = $policy->settingAllowIngredientReversal($companyId);
        $lines = $this->aggregatedParentLines($parentTransactionId);
        $returnIds = $this->links()->listDerivedIds($companyId, $parentTransactionId, 'return');
        $alreadyByItem = $this->alreadyReturnedByItem($companyId, $returnIds);

        $out = [];
        foreach ($lines as $itemId => $row) {
            $classified = $policy->classifyLine($row, $companyId, $allowIngredientReversal);
            $already   = $alreadyByItem[$itemId] ?? 0.0;
            $available = max(0.0, round($classified['qty'] - $already, 4));

            $out[] = [
                'itemSoldId'      => $classified['itemSoldId'],
                'itemId'          => $classified['itemId'],
                'name'            => $classified['name'],
                'soldQty'         => $classified['qty'],
                'alreadyReturned' => $already,
                'availableQty'    => $available,
                'unitPrice'       => $classified['unitPrice'],
                'canRestock'      => $classified['canRestock'],
                'defaultRestock'  => $classified['defaultRestock'],
                'kind'            => $classified['kind'],
            ];
        }
        return $out;
    }

    /**
     * Crea una devolución vinculada a una venta original.
     *
     * @param string      $companyId
     * @param string      $userId
     * @param string      $outletId
     * @param string|null $registerId  Puede ser null cuando se llama desde panel sin caja.
     * @param string      $parentTransactionId
     * @param array       $items    [{itemId: string, qty: float, restock?: bool, itemSoldId?: string}]
     *                    `restock` — decisión del cajero para ESTE ítem devuelto
     *                    (D2). Ausente = default de `StockReversalPolicy::classifyLine()`
     *                    para ese itemId. Clampeado a `canRestock` — pedir
     *                    reponer algo que el sistema marcó imposible se
     *                    ignora en silencio (mismo criterio que
     *                    `SaleVoidService::void()`, ver StockReversalPolicy).
     *                    `itemSoldId` — opcional, no participa del cupo
     *                    (itemId-level, ver `aggregatedParentLines()`); solo
     *                    viaja para simetría con el contrato de
     *                    `StockReversalPolicy::resolveLineDecisions()`.
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

        // D3: política de reintegro del tenant. Con 'cash'/'credit' fijado,
        // un request con el otro modo se rechaza de entrada — antes de tocar
        // la BD. Con 'ask' (default) ambos son válidos.
        $refundPolicy = $this->settingReturnRefund($companyId);
        if ($refundPolicy !== 'ask' && $refundPolicy !== $refundMode) {
            throw new \InvalidArgumentException(
                "La política de devoluciones de este comercio está fijada en '$refundPolicy' "
                . "(configuración settingReturnRefund) — no se puede devolver en modo '$refundMode'."
            );
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
                // D3: si la POLÍTICA fuerza 'credit' y la venta no tiene
                // cliente, cae a 'cash' en vez de fallar — no hay a quién
                // acreditarle, pero el comercio igual quiere poder devolver
                // la plata. Si el cajero eligió 'credit' libremente (política
                // 'ask') sin que la venta tenga cliente, sigue siendo un
                // error de input (regla ya vigente, sin cambios).
                if ($refundPolicy === 'credit') {
                    $refundMode = 'cash';
                } else {
                    throw new \InvalidArgumentException('Modo crédito requiere que la venta original tenga cliente asociado.');
                }
            }
        }

        // TX: todo el read-with-lock + writes en un bloque atómico
        $db->StartTrans();

        $newTransactionId = null;
        $stockMovements   = 0;
        $wasted           = 0;
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
                // Mismo fallback que el pre-check, re-validado DENTRO del lock
                // (la venta no cambia de cliente entre el pre-check y acá,
                // pero se mantiene el mismo criterio por consistencia).
                if ($refundPolicy === 'credit') {
                    $refundMode = 'cash';
                } else {
                    throw new \InvalidArgumentException('Modo crédito requiere que la venta original tenga cliente asociado.');
                }
            }

            $policy = $this->stockPolicy();
            $allowIngredientReversal = $policy->settingAllowIngredientReversal($companyId);

            // Líneas de la venta original, agregadas por itemId (D2 + cupo),
            // y lo ya devuelto por itemId — ambas UNA sola query cada una
            // (antes: una query POR ítem solicitado, dentro del loop).
            $aggregatedLines = $this->aggregatedParentLines($parentTransactionId);
            $returnIds       = $this->links()->listDerivedIds($companyId, $parentTransactionId, 'return');
            $alreadyByItem   = $this->alreadyReturnedByItem($companyId, $returnIds);

            // Procesar cada ítem: leer original + validar qty disponible (dentro de la TX)
            foreach ($items as $item) {
                $itemId = (string) ($item['itemId'] ?? '');
                $reqQty = (float)  ($item['qty']    ?? 0);

                if ($reqQty <= 0) {
                    throw new \InvalidArgumentException("Qty inválida para item $itemId.");
                }

                $origRow = $aggregatedLines[$itemId] ?? null;
                if ($origRow === null) {
                    throw new \InvalidArgumentException("Item $itemId no encontrado en la transacción original.");
                }

                // D2: clasificación (kind/canRestock/defaultRestock/hadStockImpact)
                // — wrapper compartido con SaleVoidService.
                $classified = $policy->classifyLine($origRow, $companyId, $allowIngredientReversal);

                $soldQty = $classified['qty'];
                // itemSoldTotal es el BRUTO de la linea y itemSoldDiscount su
                // descuento (ver lib/cart/allocate-discounts.ts): lo que el
                // cliente pago es la resta. Devolver sobre el bruto le estaria
                // reintegrando plata que nunca entrego. Precisión completa acá
                // (sin redondear unitPrice/unitDisc) — solo se redondea la
                // línea ya multiplicada por reqQty, igual que antes: redondear
                // el unitario primero desalinearía una devolución total del
                // total exacto de la venta.
                $lineTotal = abs((float) $origRow['itemsoldtotal']);
                $lineDisc  = abs((float) ($origRow['itemsolddiscount'] ?? 0));
                $unitPrice = $soldQty > 0 ? $lineTotal / $soldQty : 0.0;
                $unitDisc  = $soldQty > 0 ? $lineDisc  / $soldQty : 0.0;
                $unitCogs  = $classified['unitCogs']; // ya round(...,4), misma fórmula ponderada que antes.

                // Disponible = lo vendido menos lo ya devuelto en devoluciones
                // PREVIAS (`$alreadyByItem`) menos lo ya comprometido por OTRA
                // línea de ESTE MISMO request para el mismo itemId — sin esto
                // último, dos líneas del mismo itemId en un solo request
                // podían pasar el guard cada una por separado y devolver más
                // de lo vendido en conjunto (bug latente pre-existente a esta
                // sesión, corregido de paso porque el loop ya recorre
                // `$processedItems`).
                $alreadyReturned = $alreadyByItem[$itemId] ?? 0.0;
                foreach ($processedItems as $committed) {
                    if ($committed['itemId'] === $itemId) {
                        $alreadyReturned += $committed['qty'];
                    }
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
                $returnTotal    += $lineReturnTotal;
                $returnDiscount += $lineReturnDisc;

                // D2: decisión del cajero para ESTA línea, vía el policy
                // compartido — si el request omite `restock` para este ítem,
                // se pasa `$requestedLines=[]` para que aplique el default de
                // `classifyLine()` (mismo contrato que SaleVoidService); si lo
                // manda, se resuelve por itemSoldId/itemId (siempre único acá,
                // ver aggregatedParentLines) y se clampea a `canRestock`.
                $requestedLines = [];
                if (array_key_exists('restock', $item)) {
                    $requestedLines[] = [
                        'itemSoldId' => isset($item['itemSoldId']) ? (string) $item['itemSoldId'] : null,
                        'itemId'     => $itemId,
                        'restock'    => (bool) $item['restock'],
                    ];
                }
                $decision = $policy->resolveLineDecisions([$classified], $requestedLines);
                $restock  = $decision[0]['restock'];

                $processedItems[] = [
                    'itemId'         => $itemId,
                    'qty'            => $reqQty,
                    'lineTotal'      => $lineReturnTotal,
                    'lineDiscount'   => $lineReturnDisc,
                    // Unitario, NO multiplicado por qty: así lo espera tanto la
                    // columna itemSoldCOGS como manageStock (ver arriba).
                    'unitCogs'       => round($unitCogs, 4),
                    'kind'           => $classified['kind'],
                    'hadStockImpact' => $classified['hadStockImpact'],
                    'restock'        => $restock,
                ];
            }

            $newTransactionId = $db->GetOne('SELECT gen_random_uuid()');

            // Correlativo real (context/37 D1: SIEMPRE dentro de la TX del
            // documento — si algo de abajo falla, el rollback devuelve el
            // número y no queda hueco). Antes esta transacción se emitía sin
            // invoiceno: la devolución de venta es un documento propio hacia
            // el cliente y le corresponde numeración, igual que la factura
            // que corrige.
            $invoiceNo = DocumentNumber::allocate(
                'nota_credito',
                DocumentNumber::SCOPE_OUTLET,
                $outletId,
                $companyId,
            );

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
                    transactionpaymenttype, invoiceno,
                    transactiondate, transactionnote, transactionstatus, transactioncomplete,
                    customerid, registerid, userid, outletid, companyid, meta
                ) VALUES (?, 6, ?, ?, ?, ?, ?, NOW(), ?, 1, TRUE, ?, ?, ?, ?, ?, \'{}\')',
                [
                    $newTransactionId,
                    -abs($returnTotal),
                    -abs($returnDiscount),
                    $totalUnits,
                    $paymentsJson,
                    $invoiceNo,
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

            $wasteReasonId = null;
            foreach ($processedItems as $pi) {
                $itemSoldId = $db->GetOne('SELECT gen_random_uuid()');

                $db->Execute(
                    'INSERT INTO itemSold (
                        itemsoldid, itemid, transactionid,
                        itemsoldunits, itemsoldtotal, itemsolddiscount, itemsoldcogs,
                        itemsolddate, companyid, outletid, registerid
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
                    [
                        $itemSoldId,
                        $pi['itemId'],
                        $newTransactionId,
                        -abs($pi['qty']),
                        -abs($pi['lineTotal']),
                        -abs($pi['lineDiscount']),
                        // Signo negativo = espeja `flipOnReturn` de SaleService
                        // (lib/Sales/SaleService.php:1445) sobre el mismo campo.
                        -abs($pi['unitCogs']),
                        // D4 de context/48-escalamiento-de-datos.md (mig 156):
                        // mismos valores que la INSERT de `transaction` de
                        // arriba, en este mismo método.
                        $companyId,
                        $outletId,
                        $registerId ?: null,
                    ]
                );

                // D2: repone SOLO lo que el cajero eligió Y el sistema
                // habilita; lo que tuvo impacto real de stock y no se repuso
                // genera waste_event (wrapper compartido con SaleVoidService,
                // `source='return'` en el ledger de stock para distinguir el
                // origen del movimiento).
                if ($pi['restock']) {
                    $policy->restockLine(
                        ['itemId' => $pi['itemId'], 'kind' => $pi['kind'], 'qty' => $pi['qty'], 'unitCogs' => $pi['unitCogs']],
                        $companyId,
                        $outletId,
                        (string) $newTransactionId,
                        $userId,
                        'return'
                    );
                    $stockMovements++;
                } elseif ($pi['hadStockImpact']) {
                    $wasteReasonId ??= $policy->getOrCreateReturnWasteReasonId($companyId, $db);
                    $policy->recordWaste(
                        ['itemId' => $pi['itemId'], 'qty' => $pi['qty'], 'unitCogs' => $pi['unitCogs']],
                        $companyId,
                        $outletId,
                        $wasteReasonId,
                        $userId,
                        'Devolución de cliente' . ($note ? ': ' . $note : '')
                    );
                    $wasted++;
                }
            }

            if ($refundMode === 'credit' && !empty($parent['customerid'])) {
                $db->Execute(
                    'UPDATE contact SET contactstorecredit = contactstorecredit + ?
                     WHERE contactid = ? AND companyid = ?',
                    [abs($returnNet), $parent['customerid'], $companyId]
                );
            }

            // Nota de crédito (F5): se encola DENTRO de la transacción, igual
            // que la factura de una venta (SaleService::enqueueElectronicInvoice)
            // — si la devolución se revierte, no queda un documento fiscal
            // encolado para una devolución que nunca existió. El servicio es
            // silencioso puertas adentro (sin cuenta conectada, autoIssue off,
            // o factura original no emitida electrónicamente —
            // `EInvoiceService::parentInvoiceIsIssued()`); el try/catch es
            // para que un bug del módulo de FE no tumbe una devolución ya
            // validada.
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
            'wasted'                => $wasted,
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
