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

        // ── B4 + B6 + B7: relaciones (toTaxObj / toAddress / toTag) ─────────
        // Solo si el INSERT principal funcionó (evita FK violations en cascada
        // que ensuciarían el error real). Corren dentro de la misma transacción.
        if ($insertOk !== false && !empty($transId)) {
            $this->persistRelations($input, (string) $transId);
        }

        // ── B11 (parcial, ya acá para cerrar el trans del sub-slice) ────────
        // Los bloques B8-B10 (itemSold/manageStock/loyalty) entrarán entre el INSERT
        // y el ErrorMsg en sub-slices 35a.4-35a.5. Por ahora cerramos para que el
        // sub-slice deje el código en estado funcional (la venta básica se commitea).
        $dbError = $this->db->ErrorMsg();
        $failed  = $this->db->HasFailedTrans();
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

            // meta JSONB: shape EXACTO del legacy — ambas keys son JSON-STRINGS adentro
            // del JSON exterior (doble-encode). Los readers (OrderService:229,
            // TransactionService:41) hacen `json_decode($meta['tags'])` → DEBE ser string,
            // no array nativo. El legacy guarda `$data['tags']` (el JSON-string del front);
            // nosotros normalizamos a list<uuid> y re-encodeamos al mismo shape.
            'meta' => json_encode([
                'transactionDetails' => json_encode($saleDetail),
                'tags'               => $input->tags !== null ? json_encode($input->tags) : null,
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

    /**
     * Persiste las relaciones de la venta (B4 + B6 + B7 del legacy):
     *   - toTaxObj: snapshot de la matriz de impuestos aplicada (B4, action.php:2040)
     *   - toAddress: link a la dirección de entrega del cliente (B6, action.php:2060)
     *   - toTag: links a los tags de la venta (B7, action.php:2066)
     *
     * Todo dentro de la transacción abierta en save(). Un fallo (FK violation, etc.)
     * marca la transacción como fallida y save() lanza SaleAbortedException.
     */
    private function persistRelations(SaleInput $input, string $transId): void
    {
        // ── B4: snapshot de impuestos (toTaxObj) ────────────────────────────
        // taxObjSanitizer recorta a 11 items y formatea name/val. Devuelve false
        // si está vacío → no insertamos.
        //
        // DEUDA conocida (matchea el legacy, no es regresión): `toTaxObjText` es
        // VARCHAR(255). Con ~6+ impuestos el json_encode puede exceder 255 chars
        // → PG aborta la tx por truncación (22001) y la venta entera falla con
        // SaleAbortedException. Una venta multi-impuesto legítima podría romper.
        // Fix futuro: widening de la columna a TEXT (migración) — registrado en
        // roadmap § processData. Por ahora se preserva el comportamiento legacy.
        $taxObj = taxObjSanitizer($input->taxObj ?? []);
        if (is_array($taxObj) && $taxObj !== []) {
            $this->db->AutoExecute('toTaxObj', [
                'toTaxObjText'  => json_encode($taxObj),
                'transactionId' => $transId,
                'companyId'     => $this->ctx->companyId,
            ], 'INSERT');
        }

        // ── B6: dirección de entrega (toAddress) ────────────────────────────
        // El legacy gatea por saleType ∈ {cashsale, creditsale, order, schedule};
        // type 0/3 (path simple) siempre califica. Requiere cliente + addressId.
        // Anti-IDOR: validamos que la dirección pertenezca a ESE cliente y tenant
        // (el legacy insertaba el addressId crudo del front sin validar).
        if ($input->clientId !== null && $input->addressId !== null) {
            $addr = $this->db->Execute(
                'SELECT customerAddressId FROM customerAddress
                 WHERE customerAddressId = ? AND customerId = ? AND companyId = ? LIMIT 1',
                [$input->addressId, $input->clientId, $this->ctx->companyId]
            );
            if ($addr && !$addr->EOF) {
                $this->db->AutoExecute('toAddress', [
                    'customerAddressId' => $input->addressId,
                    'transactionId'     => $transId,
                ], 'INSERT');
            }
            // Si la dirección no matchea, la omitimos en silencio (no abortamos la
            // venta por un addressId stale — es decorativo, no afecta el cobro).
        }

        // ── B7: tags de la venta (toTag) ────────────────────────────────────
        // FIX PG: el legacy hacía `intval($ttag)` → roto, porque `totag.tagid` es
        // UUID (taxonomyId), no int. Mantenemos los UUIDs. Validamos cada tag contra
        // taxonomy (FK + tenant scope) antes de insertar — un tag inexistente
        // dispararía FK violation y abortaría la venta.
        if ($input->tags) {
            foreach ($input->tags as $tagId) {
                $tag = $this->db->Execute(
                    "SELECT taxonomyId FROM taxonomy
                     WHERE taxonomyId = ? AND taxonomyType = 'tag' AND companyId = ? LIMIT 1",
                    [$tagId, $this->ctx->companyId]
                );
                if ($tag && !$tag->EOF) {
                    $this->db->AutoExecute('toTag', [
                        'toTagType' => 0,
                        'parentId'  => $transId,
                        'tagId'     => $tagId,
                    ], 'INSERT');
                }
                // Tag inexistente o de otro tenant → omitido (no aborta la venta).
            }
        }
    }
}
