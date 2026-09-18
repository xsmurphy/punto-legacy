<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

/**
 * Tipos de transacción del POS (mapeo de `transaction.transactionType`).
 *
 * Valores int — el front y la BD usan el entero. Documentación de cada tipo
 * heredada del comentario histórico en `app/action.php:1907`.
 */
enum SaleType: int
{
    case Cashsale         = 0;  // Venta al contado
    case CashPurchase     = 1;  // Compra al contado
    case Saved            = 2;  // Venta guardada
    case Creditsale       = 3;  // Venta a crédito
    case CreditPurchase   = 4;  // Compra a crédito
    case CreditPayment    = 5;  // Pago de créditos
    case Return           = 6;  // Devolución
    case Canceled         = 7;  // Venta anulada
    case Recurring        = 8;  // Venta recursiva
    case Quote            = 9;  // Presupuesto
    case Delivery         = 10; // Delivery / remisión
    case OpenTable        = 11; // Abrir espacio
    case Order            = 12; // Orden (KDS)
    case Schedule         = 13; // Agendado (sesiones)
    case PurchaseCreditNote = 14; // Nota de crédito de compra (proveedor nos acredita/devuelve)

    /**
     * Consumo con saldo (wallet, context/74 D12-D13): COMPROBANTE INTERNO, no
     * una venta. Saca la mercadería (stock + COGS congelado por línea) y
     * debita el bolsillo en la MISMA transacción; no suma a ventas/ingresos,
     * no mueve caja, no emite factura electrónica y no numera bajo timbrado.
     *
     * ── Por qué un tipo propio y no la "venta interna" ──────────────────────
     *
     * El criterio "no suma a ingresos" vive en el TIPO, que es lo único que
     * todo lector ya mira: los reportes de ventas, los rollups (mig 42/160),
     * el ledger de Finanzas (`recordSale` solo toma el tipo 0), la unicidad
     * fiscal (`uq_transaction_expedition_invoiceno`, tipos 0/3) y la
     * facturación electrónica (`enqueueElectronicInvoice`, FC/FCR) trabajan
     * con listas BLANCAS de tipos. Un tipo nuevo queda afuera de todas por
     * construcción, sin tocar un solo reporte.
     *
     * La venta interna (tag 166227 + columna `interno`) no sirve: es un tipo 0
     * que SÍ numera, SÍ encola FE y SÍ entra a la caja, y los reportes lo
     * RESTAN después — solo si el dueño prendió `ignoreInternal`. Montar el
     * consumo ahí haría depender de un checkbox que el ingreso se cuente dos
     * veces.
     *
     * NO es creable por el POS vía `/v1/sales` ni por la cola offline
     * (`SaleInput::fromPayload` lo rechaza): el único que lo construye es
     * `SaleInput::forWalletConsumption()`, desde `/v1/pos-wallet`, que siempre
     * lo acompaña del débito. Un consumo sin débito sería mercadería regalada.
     */
    case WalletConsumption = 15;

    /** Los tipos que SaleService 35a cubre en este sub-slice. */
    public static function simplePathTypes(): array
    {
        return [self::Cashsale, self::Creditsale];
    }

    public function isSimplePathEligible(): bool
    {
        return in_array($this, self::simplePathTypes(), true);
    }

    /**
     * Tipos cuyas líneas `SaleService` persiste con itemSold + COGS + stock:
     * las ventas del camino simple y el consumo con saldo (el producto sale
     * de la góndola igual, D12).
     */
    public function movesStock(): bool
    {
        return $this->isSimplePathEligible() || $this === self::WalletConsumption;
    }

    public function isPosCreatable(): bool
    {
        return in_array($this, [self::Cashsale, self::Creditsale, self::Quote], true);
    }
}
