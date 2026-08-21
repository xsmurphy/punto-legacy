<?php
declare(strict_types=1);

namespace Punto\Api\Services;

use Punto\Api\EInvoice\EInvoiceService;
use Punto\Api\Finance\FinanceLedger;

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

    private ?TransactionLinkService  $linkSvc = null;
    private ?StockReversalPolicy     $stockPolicy = null;

    private function links(): TransactionLinkService
    {
        return $this->linkSvc ??= new TransactionLinkService();
    }

    /**
     * D2 (context/40): clasificación de línea, decisión-del-cajero,
     * reposición y registro de merma — wrapper COMPARTIDO con
     * `ReturnService::create()` (extraído de acá al implementar D2 en la
     * devolución, regla del proyecto de atacar el wrapper y no duplicar el
     * call-site). Ver `StockReversalPolicy` para el detalle.
     */
    private function stockPolicy(): StockReversalPolicy
    {
        return $this->stockPolicy ??= new StockReversalPolicy();
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

        $allowIngredientReversal = $this->stockPolicy()->settingAllowIngredientReversal($companyId);

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
                $out[] = $this->stockPolicy()->classifyLine($rs->fields, $companyId, $allowIngredientReversal);
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
            $policy = $this->stockPolicy();
            $allowIngredientReversal = $policy->settingAllowIngredientReversal($companyId);
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
                    $decisions[] = $policy->classifyLine($itemRows->fields, $companyId, $allowIngredientReversal);
                    $itemRows->MoveNext();
                }
                $itemRows->Close();
            }
            $decisions = $policy->resolveLineDecisions($decisions, $lines);

            $wasteReasonId = null;
            foreach ($decisions as $d) {
                if ($d['restock']) {
                    $policy->restockLine($d, $companyId, $resolvedOutletId, $transactionId, $userId, 'void');
                    $restocked++;
                } elseif ($d['hadStockImpact']) {
                    $wasteReasonId ??= $policy->getOrCreateReturnWasteReasonId($companyId, $db);
                    $policy->recordWaste($d, $companyId, $resolvedOutletId, $wasteReasonId, $userId, 'Anulación de venta: ' . $reason);
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
        } catch (AmbiguousStockLineException $e) {
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
