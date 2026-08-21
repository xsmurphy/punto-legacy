<?php
declare(strict_types=1);

namespace Punto\Api\Services;

use Punto\App\Domain\Inventory;
use Punto\Api\Documents\DocumentNumber;
use Punto\Api\EInvoice\EInvoiceService;
use Punto\Api\Finance\FinanceLedger;
use Punto\Api\Waste\WasteReasonService;

/**
 * SaleVoidService — anulación de ventas (F1 + F2 de
 * context/40-anulacion-y-nota-credito.md). Plan CERRADO, decisiones D1-D4
 * no opinables.
 *
 * Decisión de diseño (no relitigar): la anulación NO pisa `transactionType`
 * a 7 — agrega `voidedAt`/`voidReason`/`voidedBy` sobre la venta original
 * (mig 154). El número de factura/timbrado queda usado, tal como pidió el
 * owner. Nada se borra: ni la venta, ni sus `itemSold`, ni sus vínculos.
 * Contraste explícito con `TransactionService::voidTransaction()` (legacy,
 * type→7, borra sub-transacciones) — ese método sigue vigente para
 * transacciones que NO son venta contado/crédito.
 *
 * D2 (reposición de stock): el sistema determina qué es POSIBLE
 * (`voidOptions()`), el cajero decide qué se repone (`$lines` en `void()`).
 * Lo que no se repone y tuvo impacto real de stock genera `waste_event`.
 *
 * D4 (ventana de 48h): se calcula en runtime contra `transactionDate`, no
 * hay columna de expiración — ver mig 154.
 *
 * F2 (FE): si hay `einvoice_document` `issued` para la venta, se cancela vía
 * `EInvoiceService::cancel()` DENTRO de la misma transacción de BD que
 * marca `voidedAt` — si SIFEN rechaza, la anulación entera hace rollback
 * (una factura electrónica no puede quedar anulada local y vigente en
 * SIFEN). Es la única pieza de este flujo que NO es best-effort.
 */
final class SaleVoidService
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** D4, context/40 — aplica a TODOS los tenants, tengan o no FE. */
    public const VOID_WINDOW_HOURS = 48;

    /** Motivo lazy get-or-create en `taxonomy` (taxonomyType='wasteReason'). */
    private const WASTE_REASON_NAME = 'Devolución de cliente';

    private ?TransactionLinkService $linkSvc = null;

    private function links(): TransactionLinkService
    {
        return $this->linkSvc ??= new TransactionLinkService();
    }

    // ------------------------------------------------------------------
    // Lectura — para que la UI muestre el estado ANTES de intentar anular.
    // ------------------------------------------------------------------

    /**
     * ¿Se puede anular esta venta ahora? Motivo legible si no, para que la
     * UI deshabilite el botón CON el motivo a la vista (D4) — el guard real
     * es el de `void()`, este es solo para pintar la pantalla.
     *
     * @return array{allowed:bool, reason:?string, expiresAt:?string}
     */
    public function canVoid(string $companyId, string $transactionId): array
    {
        if (!preg_match(self::UUID_RE, $transactionId)) {
            return ['allowed' => false, 'reason' => 'id inválido', 'expiresAt' => null];
        }

        $tx = ncmExecute(
            'SELECT transactiontype, transactiondate, voidedat FROM transaction WHERE transactionid = ? AND companyid = ? LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$tx) {
            return ['allowed' => false, 'reason' => 'Venta no encontrada', 'expiresAt' => null];
        }

        $type = (int) ($tx['transactiontype'] ?? -1);
        if (!in_array($type, [0, 3], true)) {
            return ['allowed' => false, 'reason' => 'Este documento no es una venta anulable', 'expiresAt' => null];
        }
        if (!empty($tx['voidedat'])) {
            return ['allowed' => false, 'reason' => 'La venta ya fue anulada', 'expiresAt' => null];
        }

        $txDate    = (string) ($tx['transactiondate'] ?? '');
        $expiresAt = $txDate !== '' ? date('c', strtotime($txDate) + self::VOID_WINDOW_HOURS * 3600) : null;

        if ($txDate === '' || $this->hoursSince($txDate) > self::VOID_WINDOW_HOURS) {
            return [
                'allowed'   => false,
                'reason'    => 'Pasaron más de 48 horas desde la emisión: esta venta ya no se puede anular. Hacé una nota de crédito.',
                'expiresAt' => $expiresAt,
            ];
        }

        if ($this->hasVigenteAmong($companyId, $this->links()->listDerivedIds($companyId, $transactionId, 'return'))) {
            return [
                'allowed'   => false,
                'reason'    => 'Esta venta tiene devoluciones vigentes: se corrige con una nota de crédito, no se anula.',
                'expiresAt' => $expiresAt,
            ];
        }

        if ($this->hasVigenteAmong($companyId, $this->links()->listDerivedIds($companyId, $transactionId, 'credit_payment'))) {
            return [
                'allowed'   => false,
                'reason'    => 'Esta venta tiene recibos de cobro vigentes: anulalos primero.',
                'expiresAt' => $expiresAt,
            ];
        }

        return ['allowed' => true, 'reason' => null, 'expiresAt' => $expiresAt];
    }

    /**
     * Por cada línea vendida: qué es POSIBLE reponer (tabla D2 de
     * context/40) y el default visual del toggle. La UI solo puede ofrecer
     * lo que `canRestock` habilita — el cajero elige `restock` entre esas
     * opciones al llamar `void()`.
     *
     * `kind`:
     *   - 'ownStock'  — ítem con stock propio o terminado de producción
     *     previa. Se repone el ítem mismo. `canRestock` siempre true.
     *   - 'ingredientReversal' — producción directa / combo: la venta
     *     explotó receta. `canRestock` solo si el tenant tiene
     *     `settingReturnAllowIngredientReversal` activado (D2, apagado por
     *     default). Reponer repone los INSUMOS (multi-nivel), nunca el ítem.
     *   - 'service' — sin stock propio y sin receta (servicio puro).
     *     `canRestock` siempre false, no hubo nada que descontar.
     *
     * @return list<array{itemSoldId:string,itemId:string,name:string,qty:float,unitPrice:float,unitCogs:float,kind:string,canRestock:bool,defaultRestock:bool,hadStockImpact:bool}>
     */
    public function voidOptions(string $companyId, string $transactionId): array
    {
        $tx = ncmExecute(
            'SELECT transactiontype FROM transaction WHERE transactionid = ? AND companyid = ? AND transactiontype IN (0, 3) LIMIT 1',
            [$transactionId, $companyId]
        );
        if (!$tx) {
            return [];
        }

        $allowIngredientReversal = $this->settingAllowIngredientReversal($companyId);

        $rs = ncmExecute(
            'SELECT is1.itemsoldid, is1.itemid, is1.itemsoldunits, is1.itemsoldtotal, is1.itemsoldcogs, i.itemname
               FROM itemsold is1
               JOIN item i ON i.itemid = is1.itemid
              WHERE is1.transactionid = ?',
            [$transactionId],
            false,
            true
        );

        $out = [];
        if ($rs) {
            while (!$rs->EOF) {
                $out[] = $this->classifyLine($rs->fields, $companyId, $allowIngredientReversal);
                $rs->MoveNext();
            }
            $rs->Close();
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Escritura
    // ------------------------------------------------------------------

    /**
     * Anula una venta contado/crédito (transactionType 0/3). Ver docblock de
     * clase para el modelo completo.
     *
     * @param list<array{itemSoldId?:string,itemId?:string,restock?:bool}> $lines
     *        Decisión del cajero por línea. Vacío = aplica `defaultRestock`
     *        de `voidOptions()` para cada línea.
     * @return array{id:string,voidedAt:string,restocked:int,wasted:int,einvoiceCancelled:bool}
     */
    public function void(
        string  $companyId,
        string  $transactionId,
        string  $userId,
        string  $reason,
        array   $lines,
        ?string $registerId,
        ?string $outletId
    ): array {
        global $db;

        if (!preg_match(self::UUID_RE, $transactionId)) {
            apiError('id de transacción inválido', 422);
        }
        $reason = trim($reason);
        if ($reason === '') {
            apiError('Falta el motivo de la anulación', 422);
        }

        $db->StartTrans();

        $tx = ncmExecute(
            'SELECT * FROM transaction WHERE transactionid = ? AND companyid = ? AND transactiontype IN (0, 3) FOR UPDATE',
            [$transactionId, $companyId]
        );
        if (!$tx) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiNotFound('Venta no encontrada o no es un tipo anulable (contado/crédito)');
        }
        if (!empty($tx['voidedat'])) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiConflict('La venta ya fue anulada', ['errorCode' => 'ALREADY_VOIDED']);
        }

        $txDate = (string) ($tx['transactiondate'] ?? '');
        if ($txDate === '' || $this->hoursSince($txDate) > self::VOID_WINDOW_HOURS) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiUnprocessable(
                'Pasaron más de 48 horas desde la emisión: esta venta ya no se puede anular. Hacé una nota de crédito.',
                ['errorCode' => 'VOID_WINDOW_EXPIRED']
            );
        }

        // HAS_RETURNS: una factura con devoluciones vigentes no se anula —
        // se termina con nota de crédito (context/40, notas finales).
        if ($this->hasVigenteAmong($companyId, $this->links()->listDerivedIds($companyId, $transactionId, 'return'))) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiConflict(
                'Esta venta tiene devoluciones vigentes: no se puede anular, se corrige con nota de crédito.',
                ['errorCode' => 'HAS_RETURNS']
            );
        }

        // HAS_PAYMENTS: recibos de cobro vigentes tienen que anularse primero
        // (CreditPaymentService::void()) — anular la venta debajo de un
        // recibo vigente dejaría el recibo pagando una factura fantasma.
        if ($this->hasVigenteAmong($companyId, $this->links()->listDerivedIds($companyId, $transactionId, 'credit_payment'))) {
            $db->FailTrans();
            $db->CompleteTrans();
            apiConflict(
                'Esta venta tiene recibos de cobro vigentes: anulalos primero.',
                ['errorCode' => 'HAS_PAYMENTS']
            );
        }

        $resolvedOutletId = $outletId ?: (string) ($tx['outletid'] ?? '');

        $restocked = 0;
        $wasted    = 0;
        $einvoiceCancelled = false;

        try {
            // 1. Marcar la venta anulada — SIN tocar transactionType.
            // transactioncomplete=TRUE también acá: una venta a crédito
            // anulada ya no es una deuda pendiente — sin esto seguiría
            // apareciendo en OpenInvoicesService (filtra `transactionComplete
            // = false`) como si el cliente todavía la debiera. Mismo criterio
            // que CreditPaymentService::void() usa al recalcular la factura
            // que un recibo anulado deja de pagar, aplicado al otro extremo:
            // acá la deuda entera desaparece, no se recalcula.
            $db->Execute(
                'UPDATE transaction SET voidedat = now(), voidreason = ?, voidedby = ?, transactioncomplete = TRUE
                 WHERE transactionid = ? AND companyid = ?',
                [$reason, $userId, $transactionId, $companyId]
            );

            // 2. Reverso de stock línea por línea, según lo que decidió el cajero.
            $allowIngredientReversal = $this->settingAllowIngredientReversal($companyId);
            $itemRows = ncmExecute(
                'SELECT is1.itemsoldid, is1.itemid, is1.itemsoldunits, is1.itemsoldtotal, is1.itemsoldcogs, i.itemname
                   FROM itemsold is1
                   JOIN item i ON i.itemid = is1.itemid
                  WHERE is1.transactionid = ?',
                [$transactionId],
                false,
                true
            );
            $decisions = [];
            if ($itemRows) {
                while (!$itemRows->EOF) {
                    $decisions[] = $this->classifyLine($itemRows->fields, $companyId, $allowIngredientReversal);
                    $itemRows->MoveNext();
                }
                $itemRows->Close();
            }
            $decisions = $this->resolveLineDecisions($decisions, $lines);

            $wasteReasonId = null;
            foreach ($decisions as $d) {
                if ($d['restock']) {
                    $this->restockLine($d, $companyId, $resolvedOutletId, $transactionId, $userId);
                    $restocked++;
                } elseif ($d['hadStockImpact']) {
                    $wasteReasonId ??= $this->getOrCreateReturnWasteReasonId($companyId, $db);
                    $this->recordWaste($d, $companyId, $resolvedOutletId, $wasteReasonId, $userId, $reason);
                    $wasted++;
                }
            }

            // 3. Restaurar balances de pago (helper compartido con voidTransaction()).
            TransactionService::restorePaymentBalances(
                $db,
                $companyId,
                $tx['customerid'] ?? null,
                (string) ($tx['transactionpaymenttype'] ?? '')
            );

            // 4. Caja — "dentro del flujo" (context/40): si esto falla, TODA
            // la anulación revierte, no es best-effort como el endpoint legacy
            // (que probaba 4 sources a ciegas porque no sabía el tipo real).
            // Acá sabemos con certeza que el source es 'sale'.
            (new FinanceLedger())->voidBySource($companyId, 'sale', $transactionId);

            // 5. FE (F2) — cancelación SÍNCRONA, dentro de la misma TX: si
            // SIFEN rechaza, todo lo de arriba también revierte.
            // Gap conocido, NO resuelto acá (bajísima probabilidad, sin
            // reconciliación hoy — code review de esta misma tarea): si
            // Factomate confirma la cancelación pero el UPDATE local que
            // marca `einvoice_document.status='cancelled'` falla DESPUÉS
            // (conexión cortada, etc.), esta transacción hace rollback
            // completo (voidedAt/stock/waste/caja) mientras SIFEN queda
            // cancelado igual — quedaría desincronizado hasta una
            // reconciliación manual. `FactomateProvider` ya acota la espera
            // con `CURLOPT_TIMEOUT` (self::TOTAL_TIMEOUT), así que el lock
            // `FOR UPDATE` de esta transacción no queda colgado indefinido.
            $doctype = ((int) $tx['transactiontype']) === 0 ? 'FC' : 'FCR';
            $doc = ncmExecute(
                "SELECT einvoicedocid FROM einvoice_document WHERE transactionid = ? AND companyid = ? AND doctype = ? AND status = 'issued' LIMIT 1",
                [$transactionId, $companyId, $doctype]
            );
            if ($doc) {
                (new EInvoiceService())->cancel($companyId, (string) $doc['einvoicedocid'], $reason);
                $einvoiceCancelled = true;
            }

            $failed = $db->HasFailedTrans();
            $db->CompleteTrans();
            if ($failed) {
                apiError('No se pudo anular la venta: la transacción abortó', 500);
            }
        } catch (AmbiguousVoidLineException $e) {
            // 422, no 409: es un error de INPUT del request (líneas ambiguas),
            // no un conflicto de estado de la venta — mismo rollback limpio
            // que el resto de los catches de este método.
            $db->FailTrans();
            $db->CompleteTrans();
            apiError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $db->FailTrans();
            $db->CompleteTrans();
            // Mensaje de SIFEN (vía EInvoiceService::cancel) es legible —
            // se lo devolvemos al cajero como 409, no como 500 genérico.
            apiConflict(
                'No se pudo anular la venta: ' . $e->getMessage(),
                ['errorCode' => 'VOID_FAILED']
            );
        }

        // Best-effort, post-commit — la anulación ya está confirmada.
        try {
            realtimePublish('transaction', 'update', $transactionId, 'all');
        } catch (\Throwable $e) {
            // Ignorar — no crítico.
        }

        // Rollup: marcar sucio el día de la venta anulada (F4, context/40 +
        // mig 155). Mismos dominios que SaleService::save() usa para una
        // venta contado/crédito (SaleService.php:286-294) — la anulación
        // afecta los mismos 3 buckets que la creación de la venta.
        try {
            \rollupMarkDirty($companyId, ['sales', 'item_sales', 'payments'], $txDate);
        } catch (\Throwable $e) {
            error_log('[SaleVoidService] rollupMarkDirty: ' . $e->getMessage());
        }

        // Auditoría (módulo FACTURACION) — P0 encontrado en code review de
        // F1+F2: ni este método ni ninguno de sus dos callers (`sales-void.php`
        // del POS, `transactions.php?resource=void` del panel para type 0/3)
        // llamaban `sendAuditoria()`. La rama LEGACY de `transactions.php`
        // (tipos que NO son 0/3, `voidTransaction()`) sí la llama — puesta acá
        // una sola vez, ambos endpoints quedan cubiertos sin duplicarla.
        try {
            $this->sendVoidAudit($companyId, $transactionId, $userId, $reason, $registerId, $resolvedOutletId);
        } catch (\Throwable $e) {
            error_log('[SaleVoidService] sendVoidAudit: ' . $e->getMessage());
        }

        return [
            'id'                => $transactionId,
            'voidedAt'          => date('c'),
            'restocked'         => $restocked,
            'wasted'            => $wasted,
            'einvoiceCancelled' => $einvoiceCancelled,
        ];
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function hoursSince(string $dateStr): float
    {
        $ts = strtotime($dateStr);
        if ($ts === false) {
            return PHP_FLOAT_MAX;
        }
        return (time() - $ts) / 3600;
    }

    /** @param list<string> $ids */
    private function hasVigenteAmong(string $companyId, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $row = ncmExecute(
            "SELECT transactionid FROM transaction WHERE transactionid IN ($ph) AND companyid = ? AND COALESCE(transactionstatus, 1) <> 6 LIMIT 1",
            array_merge($ids, [$companyId])
        );
        return (bool) $row;
    }

    private function settingAllowIngredientReversal(string $companyId): bool
    {
        $row = ncmExecute(
            "SELECT config->>'settingReturnAllowIngredientReversal' AS val FROM company WHERE companyid = ? LIMIT 1",
            [$companyId]
        );
        return $row !== false && $row !== null && (string) ($row['val'] ?? '') === 'yes';
    }

    /**
     * Clasifica una fila de itemSold contra la tabla D2 de context/40. Usa
     * `Inventory::explodeRecipe()` (multi-nivel real) para decidir si hay
     * insumos que reponer — NO el nivel único de
     * `TransactionService::voidTransaction()` legacy, que un combo de varios
     * niveles subestima (ver context/modules/05-stock.md regla 10).
     */
    private function classifyLine(array|\ArrayAccess $f, string $companyId, bool $allowIngredientReversal): array
    {
        $itemId = (string) ($f['itemid'] ?? '');
        $units  = abs((float) ($f['itemsoldunits'] ?? 0));
        $total  = abs((float) ($f['itemsoldtotal'] ?? 0));
        $unitCogs = abs((float) ($f['itemsoldcogs'] ?? 0));

        $explodes = Inventory::saleExplodesRecipe($itemId, $companyId);
        $leaves   = $explodes ? Inventory::explodeRecipe($itemId, $companyId, $units) : [];

        if (!$explodes) {
            $kind = 'ownStock';
            $canRestock = true;
            $default    = true;
            $hadStockImpact = true;
        } elseif (is_array($leaves) && $leaves !== []) {
            $kind = 'ingredientReversal';
            $canRestock = $allowIngredientReversal;
            $default    = false; // D2: default visual = "a pérdida" para producción directa/combo.
            $hadStockImpact = true;
        } else {
            $kind = 'service';
            $canRestock = false;
            $default    = false;
            $hadStockImpact = false;
        }

        return [
            'itemSoldId'     => (string) ($f['itemsoldid'] ?? ''),
            'itemId'         => $itemId,
            'name'           => (string) ($f['itemname'] ?? ''),
            'qty'            => $units,
            'unitPrice'      => $units > 0 ? round($total / $units, 2) : 0.0,
            'unitCogs'       => round($unitCogs, 4),
            'kind'           => $kind,
            'canRestock'     => $canRestock,
            'defaultRestock' => $default,
            'hadStockImpact' => $hadStockImpact,
        ];
    }

    /**
     * Aplica la decisión del cajero (`$requestedLines`) sobre las opciones
     * calculadas, clampeando a lo que `canRestock` habilita — un cajero no
     * puede pedir reponer lo que el sistema marcó imposible. `$requestedLines`
     * vacío = defaults de cada línea (D2, "si $lines viene vacío, aplicar
     * defaults").
     *
     * P2 (code review F1+F2): el fallback por `itemId` (cuando el request no
     * manda `itemSoldId`) contagiaba la MISMA decisión a todas las líneas de
     * la venta con ese itemId — si el cliente compró el mismo ítem en dos
     * líneas separadas y el cajero solo quería reponer una, ambas terminaban
     * con el mismo `restock`. Se resuelve SIEMPRE por `itemSoldId` primero
     * (único por fila, sin ambigüedad); el fallback por `itemId` solo se
     * acepta cuando ese itemId es único entre las líneas de ESTA venta — si
     * hay 2+ líneas del mismo ítem y el request no distingue por
     * `itemSoldId`, se rechaza con 422 en vez de aplicar una decisión que no
     * se puede atribuir a una línea sin ambigüedad.
     */
    private function resolveLineDecisions(array $options, array $requestedLines): array
    {
        $itemIdCounts = [];
        foreach ($options as $opt) {
            $itemIdCounts[$opt['itemId']] = ($itemIdCounts[$opt['itemId']] ?? 0) + 1;
        }

        $reqBySoldId = [];
        $reqByItemId = [];
        foreach ($requestedLines as $l) {
            $soldId  = (string) ($l['itemSoldId'] ?? '');
            $itemId  = (string) ($l['itemId'] ?? '');
            $restock = (bool) ($l['restock'] ?? false);

            if ($soldId !== '') {
                $reqBySoldId[$soldId] = $restock;
                continue;
            }
            if ($itemId === '') {
                continue;
            }
            if (($itemIdCounts[$itemId] ?? 0) > 1) {
                // NO apiError() acá — este método corre DENTRO de la transacción
                // de BD de void() (después del UPDATE que marca voidedAt), así
                // que un `exit` directo saltearía FailTrans()/CompleteTrans().
                // Se tira una excepción catcheable; el caller decide cómo
                // responder (ver AmbiguousVoidLineException en void()).
                throw new AmbiguousVoidLineException(
                    "La venta tiene más de una línea del ítem {$itemId}: mandá itemSoldId por línea, itemId solo no alcanza para decidir cuál."
                );
            }
            $reqByItemId[$itemId] = $restock;
        }

        $decisions = [];
        foreach ($options as $opt) {
            if ($requestedLines === []) {
                $restock = $opt['defaultRestock'];
            } else {
                $restock = $reqBySoldId[$opt['itemSoldId']] ?? $reqByItemId[$opt['itemId']] ?? $opt['defaultRestock'];
            }
            if ($restock && !$opt['canRestock']) {
                $restock = false;
            }
            $decisions[] = $opt + ['restock' => $restock];
        }
        return $decisions;
    }

    private function restockLine(array $d, string $companyId, string $outletId, string $transactionId, string $userId): void
    {
        if ($d['kind'] === 'ownStock') {
            $locRow = ncmExecute('SELECT locationid FROM item WHERE itemid = ? AND companyid = ? LIMIT 1', [$d['itemId'], $companyId]);
            Inventory::manageStock([
                'itemId'        => $d['itemId'],
                'outletId'      => $outletId,
                'date'          => date('Y-m-d'),
                'locationId'    => $locRow['locationid'] ?? null,
                'count'         => $d['qty'],
                'type'          => '+',
                'source'        => 'void',
                'transactionId' => $transactionId,
                'cogs'          => $d['unitCogs'],
                'userId'        => $userId,
                'companyId'     => $companyId,
            ]);
        } elseif ($d['kind'] === 'ingredientReversal') {
            $leaves = Inventory::explodeRecipe($d['itemId'], $companyId, $d['qty']);
            foreach ((array) $leaves as $leafItemId => $leafQty) {
                if ((float) $leafQty <= 0) {
                    continue;
                }
                $locRow = ncmExecute('SELECT locationid FROM item WHERE itemid = ? AND companyid = ? LIMIT 1', [$leafItemId, $companyId]);
                Inventory::manageStock([
                    'itemId'        => $leafItemId,
                    'outletId'      => $outletId,
                    'date'          => date('Y-m-d'),
                    'locationId'    => $locRow['locationid'] ?? null,
                    'count'         => abs((float) $leafQty),
                    'type'          => '+',
                    'source'        => 'void',
                    'transactionId' => $transactionId,
                    'userId'        => $userId,
                    'companyId'     => $companyId,
                ]);
            }
        }
        // 'service': nada que reponer (ya filtrado por canRestock=false antes de llegar acá).
    }

    private function recordWaste(array $d, string $companyId, string $outletId, string $wasteReasonId, string $userId, string $voidReason): void
    {
        global $db;

        $locRow = ncmExecute('SELECT locationid FROM item WHERE itemid = ? AND companyid = ? LIMIT 1', [$d['itemId'], $companyId]);
        $cost   = round($d['unitCogs'] * $d['qty'], 2);

        $docNumber = null;
        try {
            $docNumber = DocumentNumber::allocate('merma', DocumentNumber::SCOPE_OUTLET, $outletId, $companyId);
        } catch (\Throwable $e) {
            // Sin timbrado/rango configurado para 'merma' en este outlet — la
            // merma igual se registra, sin correlativo (mismo criterio que
            // ProductionService cuando el outlet no numera mermas).
            error_log('[SaleVoidService] DocumentNumber::allocate(merma) falló: ' . $e->getMessage());
        }

        $db->Execute(
            "INSERT INTO waste_event (wasteid, companyid, outletid, locationid, itemid, qty, reasonid, source, cost, note, userid, docnumber)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, 'manual', ?, ?, ?, ?)",
            [
                $companyId,
                $outletId,
                $locRow['locationid'] ?? null,
                $d['itemId'],
                $d['qty'],
                $wasteReasonId,
                $cost,
                'Anulación de venta: ' . $voidReason,
                $userId,
                $docNumber,
            ]
        );
    }

    /**
     * Get-or-create del wasteReason "Devolución de cliente" — lazy, por
     * tenant, mismo criterio que `WasteReasonService::ensureSeed()`. Se
     * asegura primero de que el catálogo default del tenant exista (así
     * convive con los 5 defaults en vez de ser la única fila), y solo si
     * después de eso "Devolución de cliente" no está, la crea.
     */
    private function getOrCreateReturnWasteReasonId(string $companyId, \DB $db): string
    {
        $existing = ncmExecute(
            "SELECT taxonomyid FROM taxonomy WHERE companyid = ? AND taxonomytype = 'wasteReason' AND taxonomyname = ? LIMIT 1",
            [$companyId, self::WASTE_REASON_NAME]
        );
        if ($existing) {
            return (string) $existing['taxonomyid'];
        }

        (new WasteReasonService($db))->ensureSeed($companyId);

        $rs = $db->Execute(
            "INSERT INTO taxonomy (taxonomyId, companyId, taxonomyType, taxonomyName, taxonomyExtra)
             VALUES (gen_random_uuid(), ?, 'wasteReason', ?, ?::jsonb)
             RETURNING taxonomyId",
            [$companyId, self::WASTE_REASON_NAME, json_encode(['sortOrder' => 999])]
        );
        if ($rs === false || $rs->EOF) {
            throw new \RuntimeException('No se pudo crear el motivo de merma "Devolución de cliente"');
        }
        $id = (string) ($rs->fields['taxonomyid'] ?? $rs->fields['taxonomyId'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('No se pudo crear el motivo de merma "Devolución de cliente"');
        }
        return $id;
    }

    /**
     * Auditoría (módulo FACTURACION) — mismo shape que `SaleService::sendAudit()`
     * (creación de venta) y que la rama LEGACY de `api/v1/transactions.php`
     * (`voidTransaction()`, tipos que no son 0/3). `sendAuditoria()` es curl
     * best-effort (gateada por AUDITORIA_URL/TOKEN vacíos en dev — mismo guard
     * que `SaleService::sendAudit()`), por eso el caller la llama post-commit
     * dentro de su propio try/catch: un fallo acá nunca revierte la anulación
     * ya confirmada.
     */
    private function sendVoidAudit(
        string  $companyId,
        string  $transactionId,
        string  $userId,
        string  $reason,
        ?string $registerId,
        string  $outletId
    ): void {
        if (!defined('AUDITORIA_URL') || !defined('AUDITORIA_TOKEN')
            || AUDITORIA_URL === '' || AUDITORIA_TOKEN === '') {
            return; // auditoría no configurada (dev) → no-op
        }

        $userName     = getValue('contact', 'contactName', "WHERE contactId = '{$userId}'");
        $registerName = $registerId !== null && $registerId !== ''
            ? getValue('register', 'registerName', "WHERE registerId = '{$registerId}'")
            : '';
        $companyName = defined('COMPANY_NAME') ? COMPANY_NAME : '';
        $outletName  = $outletId !== '' ? getCurrentOutletName($outletId) : '';
        $transaction = ncmExecute('SELECT * FROM transaction WHERE transactionid = ? AND companyid = ? LIMIT 1', [$transactionId, $companyId]);

        sendAuditoria([
            'date'       => defined('TODAY') ? TODAY : date('Y-m-d H:i:s'),
            'user'       => $userName,
            'module'     => 'FACTURACION',
            'origin'     => 'CAJA',
            'company_id' => $companyId,
            'data'       => [
                'action'        => "El usuario {$userName} anuló una factura desde la caja {$registerName}",
                'reason'        => $reason,
                'userId'        => $userId,
                'userName'      => $userName,
                'operationData' => $transaction,
                'registerId'    => $registerId,
                'registerName'  => $registerName,
                'companyID'     => $companyId,
                'companyName'   => $companyName,
                'outletId'      => $outletId,
                'outletName'    => $outletName,
                'timestamp'     => $transaction['timestamp'] ?? null,
            ],
        ], AUDITORIA_TOKEN);
    }
}
