<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

use DB; // wrapper PDO en namespace global, en `app/includes/lib/DB.php`
use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;

/**
 * SaleService — guardado de ventas del POS (slice 35 del desacople de /app).
 *
 * Strangler-fig del handler monolítico `processData` en `app/action.php`.
 * Cubre los paths de venta migrados incrementalmente; los no migrados siguen
 * en el legacy.
 *
 * Sub-slices:
 *   35a — venta simple (cashsale type=0, creditsale type=3): sin gift card,
 *         sin schedule, sin EI, sin parent, sin recurring. ~80% del tráfico.
 *   35b — Electronic Invoice (factura electrónica PY/PE/AR).
 *   35c — Gift card sale + redemption.
 *   35d — Schedule auto-create (items con duration > 0).
 *   35e — Devolución (type=6) + pago de créditos (type=5).
 *   35f — Recurring sales (type=8) + cleanup del processData legacy.
 *
 * Estado actual: **35a.2** — B1 (parse + dupli check + StartTrans) + B3
 * (construcción del record + INSERT transaction). Los bloques B4-B16 entran
 * en sub-slices 35a.3-35a.6 (toTaxObj, toAddress, toTag, itemSold loop,
 * loyalty, emails/auditoría).
 *
 * Convención §22.9: final + readonly + DI por constructor + DTOs tipados +
 * excepciones custom. Helpers del legacy (saleArraySanitizer, countUnitSold,
 * flipOnReturn) se llaman como funciones globales — deuda registrada.
 */
final class SaleService
{
    public function __construct(
        private readonly TenantContext $ctx,
        private readonly DB $db,
    ) {
    }

    /**
     * Guarda una venta. Idempotente por `transactionUID`.
     *
     * @throws DuplicateSaleException Si el UID ya existe (el endpoint devuelve 200 con duplicated=true).
     * @throws SaleAbortedException   Si la transacción de PG falla (el endpoint devuelve 500).
     */
    public function save(SaleInput $input): SaleResult
    {
        // ── B1: idempotencia — la cola offline puede reenviar el mismo UID ────
        // (action.php:1944) Si el UID ya existe en `transaction`, devolvemos
        // duplicate (200); el front marca el UID como sincronizado y no reintenta.
        // El INSERT también tiene safety-net contra race condition vía UNIQUE
        // constraint en transactionUID (capturado en doInsertTransaction).
        $dupRow = $this->db->Execute(
            'SELECT transactionId FROM transaction WHERE transactionUID = ? LIMIT 1',
            [$input->uid]
        );
        if ($dupRow && !$dupRow->EOF) {
            throw new DuplicateSaleException(uid: $input->uid);
        }

        // ── B1: validar clientId pertenece al tenant (anti-IDOR) ────────────
        // El front puede mandar cualquier UUID en `client`. Sin validar, la venta
        // quedaría vinculada a un cliente de OTRA company → leak cross-tenant.
        // El legacy no validaba (deuda original); acá lo cerramos.
        if ($input->clientId !== null) {
            $client = $this->db->Execute(
                'SELECT contactId FROM contact WHERE contactId = ? AND companyId = ? AND type = 1 LIMIT 1',
                [$input->clientId, $this->ctx->companyId]
            );
            if (!$client || $client->EOF) {
                throw new InvalidSaleInputException(
                    "client {$input->clientId} no existe o no pertenece al tenant"
                );
            }
        }

        // ── B1: normalizar items del sale + computar totales ────────────────
        // saleArraySanitizer aplica markupt2HTML por campo y castea floats —
        // shape canónico que después leen toTaxObj/itemSold/manageStock.
        $saleDetail = saleArraySanitizer($input->sale);
        $totalUnits = countUnitSold($saleDetail);

        // ── B1: resolver userId + responsibleId ──────────────────────────────
        // (action.php:1935) Si el user del request ≠ el del JWT, registramos
        // el JWT como responsable (quien realmente operó la caja).
        $userId        = $input->userId ?? $this->ctx->userId;
        $responsibleId = ($userId !== $this->ctx->userId) ? $this->ctx->userId : null;

        // ── B1: abrir transacción ──────────────────────────────────────────
        $this->db->StartTrans();

        // ── B3: construir record de `transaction` ──────────────────────────
        $record = $this->buildTransactionRecord(
            input:         $input,
            saleDetail:    $saleDetail,
            totalUnits:    $totalUnits,
            userId:        $userId,
            responsibleId: $responsibleId,
        );

        // ── B3: INSERT principal de la venta ────────────────────────────────
        $insertOk = $this->db->AutoExecute('transaction', $record, 'INSERT');
        $transId  = $this->db->Insert_ID();
        $dbError  = $this->db->ErrorMsg();

        // ── B11 (parcial, ya acá para cerrar el trans del sub-slice) ────────
        // Los bloques B4-B10 entrarán entre el INSERT y el ErrorMsg en sub-slices
        // 35a.3-35a.5. Por ahora cerramos para que el sub-slice deje el código
        // en estado funcional (la venta básica se commitea).
        $failed = $this->db->HasFailedTrans();
        $this->db->CompleteTrans();

        if ($failed || $insertOk === false || empty($transId)) {
            // Safety-net contra race condition: si dos requests concurrentes pasan
            // el dupli check (UNIQUE en `transactionUID` previene el doble INSERT),
            // el segundo cae acá con SQLSTATE 23505. Lo convertimos a duplicate
            // para que la cola offline reciba 200 y marque el UID, no 500.
            if ($dbError !== '' && str_contains($dbError, '23505')) {
                throw new DuplicateSaleException(uid: $input->uid);
            }
            throw new SaleAbortedException(
                dbError: $dbError !== '' ? $dbError : null,
                message: 'Sale transaction aborted in INSERT',
            );
        }

        return SaleResult::created(
            transactionId: (string) $transId,
            uid:           $input->uid,
        );
    }

    /**
     * Arma el array de campos para INSERT en `transaction` (B3 del legacy).
     *
     * Notas críticas portadas del legacy (action.php:1989-2034):
     * - **`meta` JSONB doble-encode** (§22.6): `transactionDetails` y `tags`
     *   se guardan como JSON-strings dentro del JSONB `meta`, NO como columnas
     *   (fueron demoted en la migración PG). Las lecturas usan
     *   `json_decode($row['transactionDetails'])` tras `_flattenJsonb`.
     * - **UUID NULL-coalesce**: customerId / userId / transactionParentId
     *   vacíos van como NULL, no como 0 o "" (PG rechaza con "invalid input
     *   syntax for type uuid"). Ver bugs históricos del commit b45684f.
     * - **timestamp NULL-coalesce**: transactionDueDate / fromDate / toDate
     *   vacíos van como NULL (PG rechaza string "" para timestamp).
     * - **transactionComplete**: false para tipos a pagar (creditsale=3,
     *   creditpurchase=4, schedule=13) — true para el resto.
     * - **flipOnReturn**: no-op para type 0/3, solo invierte signo en type=6.
     *   Lo mantenemos por consistencia con el helper que aplica a todos los
     *   tipos en sub-slices futuros (35e devolución).
     *
     * @param array<int,array<string,mixed>> $saleDetail
     * @return array<string,mixed>
     */
    private function buildTransactionRecord(
        SaleInput $input,
        array $saleDetail,
        float $totalUnits,
        string $userId,
        ?string $responsibleId,
    ): array {
        $typeStr = (string) $input->type->value;
        $isIncomplete = in_array($input->type, [
            SaleType::Creditsale,
            SaleType::CreditPurchase,
            SaleType::Schedule,
        ], true);

        return [
            'transactionDiscount'    => flipOnReturn($typeStr, $input->discount),
            'transactionTax'         => flipOnReturn($typeStr, $input->tax),
            'transactionTotal'       => flipOnReturn($typeStr, $input->subtotal),
            'transactionUnitsSold'   => flipOnReturn($typeStr, $totalUnits),

            // meta JSONB: shape EXACTO del legacy — doble-encode obligatorio.
            'meta' => json_encode([
                'transactionDetails' => json_encode($saleDetail),
                'tags'               => $input->tags,
            ]),
            'transactionPaymentType' => json_encode($input->payment),

            // path simple: sin parentId (B2 omitido). Sub-slices futuros lo agregarán.
            'transactionParentId'    => null,
            'transactionType'        => $typeStr,
            'transactionComplete'    => $isIncomplete ? 0 : 1,

            'transactionDate'        => $input->date,
            'transactionDueDate'     => $input->dueDate, // null si vacío (DTO ya normaliza)
            'fromDate'               => null,            // path simple
            'toDate'                 => null,            // path simple
            'transactionName'        => $input->ident !== null ? strip_tags($input->ident) : null,
            'transactionNote'        => $input->note  !== null ? strip_tags($input->note)  : null,
            'invoiceNo'              => $input->invoiceNo,
            'timestamp'              => $input->timestamp,
            'transactionUID'         => $input->uid,
            'transactionCurrency'    => $input->currency,
            'transactionStatus'      => $input->status ?? 1,

            'customerId'             => $input->clientId,           // null si vacío
            'registerId'             => $this->ctx->registerId,
            'userId'                 => $userId,
            'responsibleId'          => $responsibleId,             // null si user del request == JWT
            'outletId'               => $this->ctx->outletId,
            'companyId'              => $this->ctx->companyId,
        ];
    }
}
