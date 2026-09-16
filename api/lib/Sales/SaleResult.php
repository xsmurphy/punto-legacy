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
        /** Venta original — solo cuando `duplicated` (ver `duplicate()`). */
        public readonly ?ExistingSale $existing = null,
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

    /**
     * Respuesta a un cobro que YA estaba registrado (mismo uid, mismo tenant).
     *
     * Contrato (2026-09-16): el `transactionId` es el de la venta ORIGINAL —no
     * el uid, no vacío— y viaja con su número de comprobante y su link del
     * portal. El POS arma la pantalla de éxito y el ticket con ESTOS datos: el
     * número que consumió para el reintento no es el que quedó registrado.
     */
    public static function duplicate(ExistingSale $existing): self
    {
        return new self(
            transactionId:     $existing->transactionId,
            uid:               $existing->uid,
            duplicated:        true,
            einvoicePortalUrl: $existing->einvoicePortalUrl,
            existing:          $existing,
        );
    }

    /** Shape para el envelope canónico de /api. */
    public function toApiPayload(): array
    {
        $payload = [
            'success'           => true,
            'transactionId'     => $this->transactionId,
            'uid'               => $this->uid,
            'duplicated'        => $this->duplicated,
            'einvoicePortalUrl' => $this->einvoicePortalUrl,
        ];
        if ($this->existing !== null) {
            // Solo en el duplicado: la venta que quedó registrada.
            $payload['sale'] = $this->existing->toApiPayload();
        }
        return $payload;
    }
}
