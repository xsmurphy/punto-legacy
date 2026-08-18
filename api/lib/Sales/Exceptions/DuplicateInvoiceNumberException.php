<?php
declare(strict_types=1);

namespace Punto\Api\Sales\Exceptions;

/**
 * Un comprobante con el mismo (companyId, registerId, timbrado, invoiceNo)
 * YA existe bajo OTRO transactionUID — choque real contra
 * `uq_transaction_expedition_invoiceno` (mig 145), no un reintento del mismo
 * evento (eso es `DuplicateSaleException`, que sigue cubriendo la carrera
 * contra `transactionUID`).
 *
 * P0 fiscal (context/29 §2): dos facturas con el mismo número a dos clientes
 * distintos es multa por CADA factura — esto NUNCA puede tratarse como éxito
 * silencioso. A diferencia de `DuplicateSaleException` (200, duplicated=true),
 * esto es un error real: el número que el device calculó ("último correlativo
 * de mi caja + 1") ya estaba tomado cuando llegó al server — típicamente un
 * bug de cálculo local o un correlativo viejo reemitido bajo un uid nuevo.
 *
 * §53 (context/08-convenciones-criticas.md): esto NO es una regla de negocio
 * del POS — es el mismo tipo de invariante que `holderConflict()`/
 * `REGISTER_NOT_HELD` (exclusividad de caja): "estado compartido" (numeración
 * exclusiva), la excepción explícita que §53 reconoce como bloqueable incluso
 * para una venta ya emitida. En `offline-sync.php` la venta NO se pierde: el
 * error vuelve por-item (sin tumbar el resto del lote), el front la deja en
 * la cola local con status 'failed' — recuperable para revisión manual — el
 * mismo tratamiento que ya recibe REGISTER_NOT_HELD.
 */
final class DuplicateInvoiceNumberException extends \RuntimeException
{
    public function __construct(
        public readonly string $registerId,
        public readonly ?int $invoiceNo,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? "El comprobante N° {$invoiceNo} ya fue emitido por esta caja bajo el timbrado vigente"
        );
    }
}
