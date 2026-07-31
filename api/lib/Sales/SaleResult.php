<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

/**
 * Resultado de SaleService::save(). Inmutable.
 *
 * El endpoint serializa con toApiPayload() y lo envuelve en el envelope canónico
 * `{ ok: true, data: ... }`. El BFF traduce a la respuesta legacy `{success:"true",
 * transactionId, uid, [duplicated]}` que el front ya espera.
 */
final class SaleResult
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $uid,
        public readonly bool $duplicated = false,
        /**
         * F6 — link público del portal de consulta del comprador, para imprimir
         * en el comprobante (bloque `fe_py`). null = esta venta no generó
         * documento electrónico. Viaja en la respuesta de la venta y no en una
         * llamada aparte porque el POS imprime en el mismo instante: un
         * roundtrip extra ahí es un ticket sin QR cuando la red está lenta.
         */
        public readonly ?string $einvoicePortalUrl = null,
    ) {
    }

    public static function created(string $transactionId, string $uid, ?string $einvoicePortalUrl = null): self
    {
        return new self(
            transactionId:     $transactionId,
            uid:               $uid,
            duplicated:        false,
            einvoicePortalUrl: $einvoicePortalUrl,
        );
    }

    public static function duplicate(string $existingTransactionId, string $uid): self
    {
        return new self(transactionId: $existingTransactionId, uid: $uid, duplicated: true);
    }

    /** Shape para el envelope canónico de /api. */
    public function toApiPayload(): array
    {
        return [
            'success'           => true,
            'transactionId'     => $this->transactionId,
            'uid'               => $this->uid,
            'duplicated'        => $this->duplicated,
            'einvoicePortalUrl' => $this->einvoicePortalUrl,
        ];
    }
}
