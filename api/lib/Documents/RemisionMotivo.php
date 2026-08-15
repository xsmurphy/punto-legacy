<?php
declare(strict_types=1);

namespace Punto\Api\Documents;

/**
 * Motivo de traslado de una remisión (context/42-remision.md).
 *
 * Campo de primera clase, no texto libre: condiciona qué otros campos
 * aplican y es lo que la fase SIFEN va a exigir declarar.
 *
 * 'traslado_interno' (entre sucursales/depósitos propios) NO está acá a
 * propósito — ese motivo ya tiene su propio documento completo,
 * `stock_transfer` (mig 46/129), con numeración y movimiento de stock de
 * doble entrada. `document_remision` cubre los motivos donde el destino NO
 * es un outlet propio.
 */
enum RemisionMotivo: string
{
    case Venta                = 'venta';
    case DevolucionProveedor  = 'devolucion_proveedor';
    case Consignacion         = 'consignacion';
    case Exposicion           = 'exposicion';
    case Compra               = 'compra';

    public function label(): string
    {
        return match ($this) {
            self::Venta               => 'Venta',
            self::DevolucionProveedor  => 'Devolución a proveedor',
            self::Consignacion         => 'Consignación',
            self::Exposicion           => 'Exposición / demostración',
            self::Compra               => 'Traslado por compra',
        };
    }

    /**
     * true si este motivo mueve stock por su cuenta (`document_remision` NO
     * lo mueve nunca — ver docblock de la mig 137). Documentación en código
     * de la decisión, no un flag que cambie comportamiento: sirve para que un
     * futuro caller no reintroduzca un movimiento de stock acá pensando que
     * falta.
     */
    public function ownsStockMovementElsewhere(): bool
    {
        return match ($this) {
            self::Venta              => true,  // la factura, al emitirse
            self::DevolucionProveedor => true, // PurchaseCreditNoteService (transactionType 14)
            self::Compra              => true, // PurchasesService (types 1/4)
            self::Consignacion, self::Exposicion => false, // sin dueño hoy — abierto al owner
        };
    }
}
