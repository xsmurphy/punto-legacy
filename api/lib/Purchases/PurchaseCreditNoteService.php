<?php
declare(strict_types=1);

namespace Punto\Api\Purchases;

use Punto\Api\Services\TransactionLinkService;

/**
 * Notas de crédito de compra (transactionType = 14).
 *
 * Caso real: compramos N unidades, algunas llegan dañadas, el proveedor nos
 * emite una nota de crédito — total o parcial. Documento hermano de la
 * compra (types 1/4): vive en `transaction`/`itemSold` igual que cualquier
 * otro documento del dominio, vinculado a la compra original vía
 * `transaction_link` kind='purchase_credit_note' (mig 122). Espejo
 * estructural de `Services\ReturnService` (devolución de VENTA) — MISMA
 * mecánica de validación/lock/prorrateo, semántica de plata OPUESTA: acá la
 * plata entra o reduce una deuda nuestra, no sale de una caja.
 *
 * Dos ejes independientes que decide el operador al emitirla:
 *   - `refundMode`:
 *       'cash'   → el proveedor nos devuelve plata. La transacción nace con
 *                  una línea de pago ({type:'cash'}) que FinanceLedger::
 *                  recordPurchaseCreditNote() lee para registrar un INGRESO
 *                  (post-commit, best-effort, igual criterio que
 *                  ReturnService/recordReturn).
 *       'credit' → NO mueve caja. Reduce el saldo pendiente de una compra a
 *                  CRÉDITO (type=4) — sin columna nueva: se linkea con el
 *                  mismo `transaction_link` que ya usan los pagos a
 *                  proveedor, y los dos lugares que calculan "cuánto se
 *                  saldó" de una compra a crédito
 *                  (Reports\PurchasesService::payedByParent,
 *                  Finance\OpenInvoicesService::payedByParent) suman también
 *                  los derivados kind='purchase_credit_note'. El saldo baja
 *                  solo porque ahora entra en esa resta — no hay un segundo
 *                  lugar que lo calcule.
 *   - `affectsStock`: true → el proveedor se lleva la mercadería dañada,
 *     Inventory::manageStock resta stock. false → bonificación/descuento
 *     sin devolución física, no toca stock.
 *
 * Validación pre-TX (solo lecturas) + re-validación DENTRO de la TX con
 * `FOR UPDATE` en la fila padre — evita que dos requests concurrentes lean
 * "ya acreditado" antes de que el primero escriba (mismo patrón que
 * ReturnService).
 */
final class PurchaseCreditNoteService
{
    private ?TransactionLinkService $linkSvc = null;

    private function links(): TransactionLinkService
    {
        return $this->linkSvc ??= new TransactionLinkService();
    }

    /** 'YYYY-MM-DD' válido → tal cual; cualquier otra cosa (vacío, mal formada) → null. Nunca inventa un default. */
    private static function normalizeSimpleDate(?string $val): ?string
    {
        $val = $val !== null ? trim($val) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : null;
    }

    /**
     * @param array<int,array{itemId:string,qty:float}> $items
     * @param string $refundMode 'cash' | 'credit'
     * @param array{docPrefix?:?string,docNo?:?string,docDate?:?string,authNo?:?string,authNoDueDate?:?string}|null $supplierDoc
     *        Número de comprobante + timbrado IMPRESOS en la nota de crédito
     *        del PROVEEDOR (context/29 §5, mig 144) — documento ajeno, NO
     *        generamos correlativo interno. Todo opcional/nullable.
     */
    public function create(
        string  $companyId,
        string  $userId,
        string  $outletId,
        string  $parentTransactionId,
        array   $items,
        string  $refundMode,
        bool    $affectsStock,
        ?string $note,
        ?array  $supplierDoc = null
    ): array {
        global $db;

        if (empty($items)) {
            throw new \InvalidArgumentException('No hay items para acreditar.');
        }
        if (!in_array($refundMode, ['cash', 'credit'], true)) {
            throw new \InvalidArgumentException('refundMode debe ser "cash" o "credit".');
        }

        // Validación rápida pre-TX (solo lookups de solo lectura, sin locks).
        $parentCheck = ncmExecute(
            'SELECT transactionId, transactionType, transactionComplete, transactionStatus
             FROM transaction
             WHERE transactionId = ? AND companyId = ? AND transactionType IN (1, 4)',
            [$parentTransactionId, $companyId]
        );
        if (!$parentCheck) {
            throw new \InvalidArgumentException('Compra no encontrada o no válida para nota de crédito.');
        }
        if ($refundMode === 'credit') {
            $this->assertCreditEligible($parentCheck);
        }

        // TX: todo el read-with-lock + writes en un bloque atómico.
        $db->StartTrans();

        $newTransactionId = null;
        $stockMovements   = 0;
        $creditTotal      = 0.0; // bruto acreditado (espeja itemSoldTotal)
        $creditDiscount   = 0.0; // descuento proporcional (0 en compras: itemSold no trae descuento por línea)
        $creditTax        = 0.0; // impuesto proporcional (informativo — no se suma al total, mismo criterio "tax incluido" que PurchasesService::create)
        $processedItems   = [];

        try {
            // Lock de la fila padre — evita que dos requests concurrentes lean
            // `alreadyCredited` antes de que el primero escriba.
            $parent = $db->GetRow(
                'SELECT transactionId, transactionType, transactionComplete, transactionStatus, supplierId, outletId
                 FROM transaction
                 WHERE transactionId = ? AND companyId = ? AND transactionType IN (1, 4)
                 FOR UPDATE',
                [$parentTransactionId, $companyId]
            );
            if (!$parent) {
                throw new \InvalidArgumentException('Compra no encontrada o no válida para nota de crédito.');
            }
            if ($refundMode === 'credit') {
                $this->assertCreditEligible($parent);
            }

            // Mergear líneas con el mismo itemId ANTES de validar: si no, dos
            // líneas repetidas del mismo item pasarían la validación de "qty
            // disponible" cada una por separado (el chequeo de `alreadyCredited`
            // solo ve NC previas ya COMMITEADAS, no las líneas que se están
            // procesando en esta misma request) y se acreditaría de más.
            $mergedItems = [];
            foreach ($items as $item) {
                $itemId = (string) ($item['itemId'] ?? '');
                $qty    = (float)  ($item['qty']    ?? 0);
                $mergedItems[$itemId] = ($mergedItems[$itemId] ?? 0) + $qty;
            }

            // Qty ya acreditada por NC previas NO anuladas — se lee UNA vez
            // (no cambia dentro de esta request; las líneas que se están
            // creando ahora se cubren con el merge de arriba, no con esto).
            // Anuladas (transactionStatus=6) no cuentan: void() las excluye a
            // propósito para que una NC anulada libere el cupo.
            $ncIds = $this->links()->listDerivedIds($companyId, $parentTransactionId, 'purchase_credit_note');

            // Procesar cada ítem: leer línea original + validar qty disponible (dentro de la TX).
            foreach ($mergedItems as $itemId => $reqQty) {
                if ($reqQty <= 0) {
                    throw new \InvalidArgumentException("Qty inválida para item $itemId.");
                }

                // AGREGADO por itemId, no GetRow: una misma compra puede tener
                // el mismo producto en DOS líneas (el form de /purchase no las
                // fusiona). Leer una sola daría un cupo disponible menor al
                // real y bloquearía acreditar lo que sí se compró. El taxid se
                // toma con MIN por determinismo — si dos líneas del mismo item
                // tuvieran impuestos distintos no hay un único "el" impuesto
                // de la NC, y ese caso no lo produce el flujo de compras.
                // Tercer bug pre-existente encontrado al ejercitar este método
                // (desbloqueante, sin relación con context/29 §5, ver
                // verify_supplier_document.php caso 3): `itemSold` NO tiene
                // columna real `taxid` (db-schema-postgres.sql) — PurchasesService
                // ::create() manda `taxId` a `ncmInsert`, que lo enruta al JSONB
                // `meta` (Schema::split(), campo desconocido) con la clave
                // camelCase TAL CUAL vino: `meta->>'taxId'`, no `meta->>'taxid'`.
                // `MIN(taxid::text)` como columna real rechazaba con "column
                // taxid does not exist" y create() SIEMPRE fallaba con "Item no
                // encontrado" para cualquier línea con impuesto — o sea,
                // prácticamente cualquier compra real. Se corrige leyendo del
                // JSONB.
                $origItem = $db->GetRow(
                    // itemSold sin comillas a propósito: la tabla se creó SIN
                    // quotes → Postgres la plegó a minúsculas (itemsold).
                    // Citarla como "itemSold" exige match exacto de mayúsculas
                    // y Postgres tira "relation does not exist".
                    // `taxid` NO existe como columna: el dato vive en el JSONB
                    // `meta->>'taxId'` (verificado 2026-08-19 al arreglar la NC
                    // de compra, que fallaba justamente por leer esa columna).
                    "SELECT SUM(itemsoldunits)                 AS itemsoldunits,
                            SUM(itemsoldtotal)                 AS itemsoldtotal,
                            SUM(COALESCE(itemsolddiscount, 0)) AS itemsolddiscount,
                            SUM(COALESCE(itemsoldtax, 0))      AS itemsoldtax,
                            MIN(meta->>'taxId')                AS taxid
                     FROM itemSold
                     WHERE transactionid = ? AND itemid = ?
                     HAVING COUNT(*) > 0",
                    [$parentTransactionId, $itemId]
                );
                if (!$origItem) {
                    throw new \InvalidArgumentException("Item $itemId no encontrado en la compra original.");
                }

                $purchasedQty = abs((float) $origItem['itemsoldunits']);
                $lineTotal    = abs((float) $origItem['itemsoldtotal']);
                $lineDisc     = abs((float) ($origItem['itemsolddiscount'] ?? 0));
                $lineTax      = abs((float) ($origItem['itemsoldtax'] ?? 0));
                $unitPrice    = $purchasedQty > 0 ? $lineTotal / $purchasedQty : 0.0;
                $unitDisc     = $purchasedQty > 0 ? $lineDisc  / $purchasedQty : 0.0;
                $unitTax      = $purchasedQty > 0 ? $lineTax   / $purchasedQty : 0.0;

                $alreadyCredited = 0.0;
                if ($ncIds !== []) {
                    $ph = implode(',', array_fill(0, count($ncIds), '?'));
                    $alreadyCredited = (float) $db->GetOne(
                        "SELECT COALESCE(SUM(ABS(is2.itemsoldunits)), 0)
                         FROM itemSold is2
                         JOIN transaction t2 ON t2.transactionid = is2.transactionid
                         WHERE is2.transactionid IN ($ph) AND is2.itemid = ? AND COALESCE(t2.transactionstatus, 1) <> 6",
                        [...$ncIds, $itemId]
                    );
                }

                $available = $purchasedQty - $alreadyCredited;
                if ($reqQty > $available + 0.001) {
                    throw new \InvalidArgumentException(
                        "Item $itemId: qty solicitada ($reqQty) supera disponible ($available)."
                    );
                }

                $lineCreditTotal = round($unitPrice * $reqQty, 2);
                $lineCreditDisc  = round($unitDisc  * $reqQty, 2);
                $lineCreditTax   = round($unitTax   * $reqQty, 2);
                $unitCost        = $purchasedQty > 0 ? $lineTotal / $purchasedQty : 0.0;

                $creditTotal    += $lineCreditTotal;
                $creditDiscount += $lineCreditDisc;
                $creditTax      += $lineCreditTax;

                $processedItems[] = [
                    'itemId'       => $itemId,
                    'qty'          => $reqQty,
                    'lineTotal'    => $lineCreditTotal,
                    'lineDiscount' => $lineCreditDisc,
                    'lineTax'      => $lineCreditTax,
                    'taxId'        => $origItem['taxid'] ?? null,
                    'unitCost'     => $unitCost,
                ];
            }

            $newTransactionId = $db->GetOne('SELECT gen_random_uuid()');

            // A diferencia de ReturnService (que guarda el NETO en negativo
            // porque la venta pierde plata), acá `transactionTotal` va en
            // POSITIVO y NETO de descuento — mismo shape que usa una compra
            // (PurchasesService::create: transactionTotal = total - discount)
            // y que un pago a proveedor (CreditPaymentService: transactionTotal
            // = amount). Reports\PurchasesService::payedByParent() y
            // Finance\OpenInvoicesService::payedByParent() suman estos valores
            // SIN abs() / con abs() respectivamente para reducir el saldo —
            // negativo acá invertiría esa resta.
            $creditNet = round($creditTotal - $creditDiscount, 2);

            $paymentsJson = null;
            if ($refundMode === 'cash') {
                // El proveedor nos devuelve plata: mismo shape {type,total}
                // que lee FinanceLedger::recordPaymentLines (grupo por cuenta
                // resuelta, admite pago dividido aunque acá siempre mandamos 1).
                $paymentsJson = json_encode([[
                    'type'  => 'cash',
                    'total' => $creditNet,
                ]]);
            }
            // 'credit' no genera línea de pago — no mueve ninguna cuenta, solo
            // entra en el cálculo de saldo pendiente vía el link (ver docblock).

            $totalUnits = -array_sum(array_column($processedItems, 'qty'));

            // Comprobante+timbrado del PROVEEDOR (mig 144) — documento ajeno,
            // sin correlativo propio (owner 2026-08-17). Todo NULL si el
            // caller no lo manda (compat con integraciones viejas).
            $docPrefix     = (isset($supplierDoc['docPrefix']) && $supplierDoc['docPrefix'] !== '') ? (string) $supplierDoc['docPrefix'] : null;
            $docNo         = isset($supplierDoc['docNo']) && $supplierDoc['docNo'] !== '' ? (int) $supplierDoc['docNo'] : null;
            $docDate       = self::normalizeSimpleDate($supplierDoc['docDate'] ?? null);
            $authNo        = (isset($supplierDoc['authNo']) && $supplierDoc['authNo'] !== '') ? (string) $supplierDoc['authNo'] : null;
            $authNoDueDate = self::normalizeSimpleDate($supplierDoc['authNoDueDate'] ?? null);

            $db->Execute(
                'INSERT INTO transaction (
                    transactionid, transactiontype,
                    transactiontotal, transactiondiscount, transactiontax, transactionunitssold,
                    transactionpaymenttype,
                    transactiondate, transactionnote, transactionstatus, transactioncomplete,
                    supplierid, userid, outletid, companyid, meta,
                    supplierdocprefix, supplierdocno, supplierdocdate,
                    supplierauthno, supplierauthnoduedate
                ) VALUES (?, 14, ?, ?, ?, ?, ?, NOW(), ?, 1, TRUE, ?, ?, ?, ?, \'{}\',
                    ?, ?, ?, ?, ?)',
                [
                    $newTransactionId,
                    $creditNet,
                    $creditDiscount,
                    $creditTax,
                    $totalUnits,
                    $paymentsJson,
                    $note,
                    $parent['supplierId'] ?? null,
                    $userId,
                    $outletId,
                    $companyId,
                    $docPrefix,
                    $docNo,
                    $docDate,
                    $authNo,
                    $authNoDueDate,
                ]
            );
            // Vínculo NC → compra original (mig 122, kind='purchase_credit_note').
            // Dentro de la misma TX: si el commit falla, el link tampoco queda.
            $this->links()->link($companyId, $parentTransactionId, (string) $newTransactionId, 'purchase_credit_note');

            foreach ($processedItems as $pi) {
                $itemSoldId = $db->GetOne('SELECT gen_random_uuid()');

                // itemSold no tiene columna real `taxid` (mismo bug del
                // SELECT de arriba) — el taxId va al JSONB `meta`, misma
                // clave camelCase que usa PurchasesService::create() vía
                // ncmInsert/Schema::split() (`meta->>'taxId'`), para que la
                // línea de la NC sea legible con el mismo criterio que
                // cualquier otra línea de itemSold.
                $db->Execute(
                    'INSERT INTO itemSold (
                        itemsoldid, itemid, transactionid,
                        itemsoldunits, itemsoldtotal, itemsolddiscount, itemsoldtax,
                        itemsolddate, meta, companyid, outletid
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
                    [
                        $itemSoldId,
                        $pi['itemId'],
                        $newTransactionId,
                        // Unidades NEGATIVAS — mismo criterio que ReturnService:
                        // una NC total cancela EXACTAMENTE a la línea de compra.
                        -abs($pi['qty']),
                        $pi['lineTotal'],
                        $pi['lineDiscount'],
                        $pi['lineTax'],
                        json_encode($pi['taxId'] !== null && $pi['taxId'] !== '' ? ['taxId' => $pi['taxId']] : []),
                        // D4 de context/48-escalamiento-de-datos.md (mig 156).
                        // Sin registerid: esta transacción (tipo 14, NC de
                        // compra) no tiene columna registerid — es un flujo
                        // de panel, no de caja (ver INSERT de `transaction`
                        // más arriba en este mismo método).
                        $companyId,
                        $outletId,
                    ]
                );

                if ($affectsStock) {
                    $result = \Punto\App\Domain\Inventory::manageStock([
                        'itemId'        => $pi['itemId'],
                        'outletId'      => $outletId,
                        'date'          => date('Y-m-d'),
                        'locationId'    => null,
                        'count'         => $pi['qty'],
                        'type'          => '-', // el proveedor se lleva la mercadería — resta stock (espejo de PurchasesService::create, que suma)
                        'source'        => 'purchase_credit_note',
                        'transactionId' => $newTransactionId,
                        'cogs'          => $pi['unitCost'],
                        'supplierId'    => $parent['supplierId'] ?? null,
                        'userId'        => $userId,
                        'companyId'     => $companyId,
                    ]);
                    if ($result !== false) {
                        $stockMovements++;
                    }
                }
            }

            $db->CompleteTrans();
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            throw $e;
        }

        // Finanzas: la NC en modo 'cash' hace entrar plata, genera su
        // movimiento derivado igual que una compra genera el suyo
        // (PurchasesService → recordPurchase). Post-commit y best-effort: un
        // fallo del ledger nunca revierte una NC ya confirmada. Modo 'credit'
        // no mueve caja — el método lo filtra por ausencia de línea de pago.
        try {
            (new \Punto\Api\Finance\FinanceLedger())->recordPurchaseCreditNote($companyId, (string) $newTransactionId);
        } catch (\Throwable $e) {
            error_log('[PurchaseCreditNoteService] FinanceLedger::recordPurchaseCreditNote falló para ' . $newTransactionId . ': ' . $e->getMessage());
        }

        return [
            'id'             => $newTransactionId,
            'total'          => $creditNet,
            'refundMode'     => $refundMode,
            'affectsStock'   => $affectsStock,
            'stockMovements' => $stockMovements,
        ];
    }

    /**
     * Anula (soft-void) una nota de crédito de compra. Mismo criterio que
     * `PurchasesService::void()`: NO borra la fila, marca transactionStatus=6.
     * Revierte el stock SOLO si la NC lo había afectado — se detecta leyendo
     * `stock` (tabla de movimientos), no un flag propio, para no duplicar el
     * dato: si `affectsStock` fue false al crearla, no hay filas ahí y no hay
     * nada que revertir.
     *
     * Al marcar status=6 la NC deja de contar como "ya acreditado"
     * (create() filtra `transactionStatus <> 6`) y de sumar en los cálculos
     * de saldo pendiente (`Reports\PurchasesService::payedByParent`,
     * `Finance\OpenInvoicesService::payedByParent` — ambos ya excluyen status
     * anulado en sus queries de compras/pagos). El ledger ('cash') se
     * revierte aparte, por el caller, vía `FinanceLedger::voidBySource`
     * (mismo patrón que `api/v1/purchases.php` DELETE).
     *
     * @throws \RuntimeException si la NC no existe o ya fue anulada.
     */
    public function void(string $id, string $companyId, string $userId): array
    {
        global $db;

        $row = ncmExecute(
            'SELECT transactionId, transactionStatus, outletId, supplierId, transactionDate
             FROM transaction
             WHERE transactionId = ? AND companyId = ? AND transactionType = 14
             LIMIT 1',
            [$id, $companyId]
        );
        if (!$row) {
            throw new \RuntimeException('Nota de crédito no encontrada');
        }
        if ((int) $row['transactionstatus'] !== 1) {
            throw new \RuntimeException('La nota de crédito ya fue anulada');
        }

        $outletId   = (string) $row['outletid'];
        $supplierId = $row['supplierid'] !== null ? (string) $row['supplierid'] : null;
        $date       = (string) $row['transactiondate'];

        $moved = ncmExecute(
            "SELECT DISTINCT itemid FROM stock WHERE transactionid = ? AND companyid = ? AND stocksource = 'purchase_credit_note'",
            [$id, $companyId],
            false,
            false,
            true
        );
        $movedItemIds = [];
        foreach ((is_array($moved) ? $moved : []) as $m) {
            $movedItemIds[(string) $m['itemid']] = true;
        }

        // Fix code-review mig 157 (context/48 D7): anular una NC de compra de
        // un período cerrado cambiaría el hecho económico de un mes ya
        // cerrado — bloqueado por diseño, se corrige con un documento nuevo
        // en el período abierto. Pre-check ANTES de abrir la TX y tocar
        // stock — mismo criterio que PurchasesService::void().
        $periodClosed = $db->GetOne('SELECT period_is_closed(?, ?)', [$companyId, $date]);
        if ($periodClosed === true || $periodClosed === 't' || $periodClosed === '1') {
            throw new \Punto\Api\Support\PeriodClosedException(
                '',
                0,
                null,
                'El documento pertenece a un período cerrado; emití una nota de crédito / ajuste en el período abierto.'
            );
        }

        $db->StartTrans();

        ncmExecute(
            'UPDATE transaction SET transactionStatus = 6, updated_at = NOW()
              WHERE transactionId = ? AND companyId = ?',
            [$id, $companyId]
        );

        $stockReverted = 0;
        if ($movedItemIds !== []) {
            // Una LÍNEA por fila, no una por ítem: la PK de itemSold es
            // `itemSoldId` y NADA impide que el mismo ítem aparezca en dos
            // líneas de la misma NC (distinto precio, distinto lote, o
            // simplemente cargado dos veces). Esto se leía con `getAssoc`,
            // que indexa por la primera columna proyectada (`itemid`) y pisa
            // las repetidas: de dos líneas del mismo ítem sobrevivía UNA y el
            // void reponía solo esa cantidad. No es un reporte que sale mal —
            // es stock que queda corto en la BD, en silencio y sin forma de
            // detectarlo después. Ver `DB::GetAssoc()`.
            $lines = ncmRows(
                'SELECT itemsoldid, itemid, itemsoldunits, itemsoldtotal FROM itemSold WHERE transactionid = ?',
                [$id]
            );
            foreach ($lines as $l) {
                $itemId = (string) $l['itemid'];
                if (!isset($movedItemIds[$itemId])) {
                    continue;
                }
                $qty = abs((float) $l['itemsoldunits']);
                if ($qty <= 0) {
                    continue;
                }
                $unitCost = abs((float) $l['itemsoldtotal']) / $qty;

                $result = \Punto\App\Domain\Inventory::manageStock([
                    'itemId'        => $itemId,
                    'outletId'      => $outletId,
                    'count'         => $qty,
                    'type'          => '+', // reversa: la NC había restado, el void repone
                    'cogs'          => $unitCost,
                    'supplierId'    => $supplierId,
                    'source'        => 'purchase_credit_note-void',
                    'transactionId' => $id,
                    'locationId'    => null,
                    'userId'        => $userId,
                    'companyId'     => $companyId,
                    'date'          => $date,
                ]);
                if ($result !== false) {
                    $stockReverted++;
                }
            }
        }

        $failed = $db->HasFailedTrans();
        $db->CompleteTrans();
        if ($failed) {
            throw new \RuntimeException('No se pudo anular la nota de crédito: la transacción abortó');
        }

        return ['id' => $id, 'status' => 6, 'stockReverted' => $stockReverted];
    }

    /**
     * Notas de crédito emitidas para una compra — fecha, total y modo, para
     * mostrarlas en el detalle de la compra (equivalente a
     * ReturnService::listForParent).
     */
    public function listForParent(string $parentTransactionId, string $companyId): array
    {
        $ids = $this->links()->listDerivedIds($companyId, $parentTransactionId, 'purchase_credit_note');
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rs = ncmExecute(
            "SELECT transactionid, transactiontotal, transactiondate, transactionnote,
                    transactionstatus, transactionpaymenttype,
                    supplierdocprefix, supplierdocno, supplierdocdate,
                    supplierauthno, supplierauthnoduedate
             FROM transaction
             WHERE transactionid IN ($ph) AND companyid = ? AND transactiontype = 14
             ORDER BY transactiondate DESC",
            [...$ids, $companyId],
            false,
            true
        );

        $rows = [];
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $paymentLines = json_decode((string) ($f['transactionpaymenttype'] ?? ''), true);
                $rows[] = [
                    'id'            => (string) $f['transactionid'],
                    'total'         => (float) $f['transactiontotal'],
                    'date'          => (string) $f['transactiondate'],
                    'note'          => $f['transactionnote'] ?? null,
                    'status'        => (int) $f['transactionstatus'],
                    // Sin línea de pago = modo 'credit' (ver create(): 'credit' nunca escribe transactionPaymentType).
                    'refundMode'    => (is_array($paymentLines) && $paymentLines !== []) ? 'cash' : 'credit',
                    'docPrefix'     => $f['supplierdocprefix'] !== null ? (string) $f['supplierdocprefix'] : null,
                    'docNo'         => $f['supplierdocno'] !== null ? (int) $f['supplierdocno'] : null,
                    'docDate'       => $f['supplierdocdate'] !== null ? (string) $f['supplierdocdate'] : null,
                    'authNo'        => $f['supplierauthno'] !== null ? (string) $f['supplierauthno'] : null,
                    'authNoDueDate' => $f['supplierauthnoduedate'] !== null ? (string) $f['supplierauthnoduedate'] : null,
                ];
                $rs->MoveNext();
            }
        }
        return $rows;
    }

    /**
     * itemId → cantidad ya acreditada por NC previas NO anuladas. El detalle
     * de compra lo expone para que el front calcule, por línea, el máximo
     * disponible = comprado - ya acreditado (mismo dato que create() valida
     * server-side — acá solo se lee para pintar el límite en el form).
     *
     * @return array<string, float>
     */
    public function creditedQtyByItem(string $parentTransactionId, string $companyId): array
    {
        $ids = $this->links()->listDerivedIds($companyId, $parentTransactionId, 'purchase_credit_note');
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rs = ncmExecute(
            "SELECT is2.itemid AS itemid, SUM(ABS(is2.itemsoldunits)) AS qty
             FROM itemSold is2
             JOIN transaction t2 ON t2.transactionid = is2.transactionid
             WHERE is2.transactionid IN ($ph) AND t2.companyid = ? AND COALESCE(t2.transactionstatus, 1) <> 6
             GROUP BY is2.itemid",
            [...$ids, $companyId],
            false,
            true
        );
        $map = [];
        if ($rs) {
            while (!$rs->EOF) {
                $f = $rs->fields;
                $map[(string) $f['itemid']] = (float) $f['qty'];
                $rs->MoveNext();
            }
        }
        return $map;
    }

    /** 'credit' solo aplica a una compra a crédito (type=4) todavía pendiente (no saldada, no anulada). */
    private function assertCreditEligible(array|\CaseInsensitiveArray $parent): void
    {
        if ((int) ($parent['transactionType'] ?? 0) !== 4) {
            throw new \InvalidArgumentException('El modo "a cuenta del saldo" solo aplica a compras a crédito.');
        }
        if ((int) ($parent['transactionStatus'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('La compra fue anulada — no se puede acreditar a cuenta.');
        }
        if (!empty($parent['transactionComplete'])) {
            throw new \InvalidArgumentException('La compra ya está saldada — no hay saldo pendiente para acreditar.');
        }
    }
}
