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
    ) {
    }

    public static function created(string $transactionId, string $uid): self
    {
        return new self(transactionId: $transactionId, uid: $uid, duplicated: false);
    }

    public static function duplicate(string $existingTransactionId, string $uid): self
    {
        return new self(transactionId: $existingTransactionId, uid: $uid, duplicated: true);
    }

    /** Shape para el envelope canónico de /api. */
    public function toApiPayload(): array
    {
        return [
            'success'       => true,
            'transactionId' => $this->transactionId,
            'uid'           => $this->uid,
            'duplicated'    => $this->duplicated,
        ];
    }
}
