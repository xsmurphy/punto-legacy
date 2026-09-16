<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

/**
 * Venta YA registrada, identificada por su `transactionUID`. Inmutable.
 *
 * Es lo que se le devuelve al POS cuando el mismo cobro llega dos veces (la
 * cola offline reenvía, o la caja reintenta tras un resultado ambiguo) y lo que
 * resuelve un "cobro pendiente" consultado por uid (`GET /v1/sales?uid=`).
 *
 * Lleva lo que la pantalla de éxito y el ticket necesitan de la venta ORIGINAL
 * —no de la del reintento—: el número de comprobante con su serie congelada y
 * el link del portal. Sin esto el POS mostraba el número que acababa de
 * consumir para el reintento, que NO es el que quedó registrado.
 */
final class ExistingSale
{
    public function __construct(
        public readonly string $transactionId,
        public readonly string $uid,
        public readonly int $type,
        public readonly string $outletId,
        public readonly ?string $registerId,
        public readonly ?int $invoiceNo,
        public readonly ?string $invoicePrefix,
        public readonly ?string $invoiceSerie,
        public readonly ?string $invoiceAuth,
        public readonly ?float $total,
        public readonly ?string $date,
        public readonly ?string $einvoicePortalUrl,
    ) {
    }

    /** Shape para el envelope canónico de /api. */
    public function toApiPayload(): array
    {
        return [
            'transactionId'     => $this->transactionId,
            'uid'               => $this->uid,
            'type'              => $this->type,
            'invoiceNo'         => $this->invoiceNo,
            'invoicePrefix'     => $this->invoicePrefix,
            'invoiceSerie'      => $this->invoiceSerie,
            'invoiceAuth'       => $this->invoiceAuth,
            'total'             => $this->total,
            'date'              => $this->date,
            'einvoicePortalUrl' => $this->einvoicePortalUrl,
        ];
    }
}
