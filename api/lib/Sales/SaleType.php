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
    case OpenTable        = 11; // Abrir mesa
    case Order            = 12; // Orden (KDS)
    case Schedule         = 13; // Agendado (sesiones)
    case PurchaseCreditNote = 14; // Nota de crédito de compra (proveedor nos acredita/devuelve)

    /** Los tipos que SaleService 35a cubre en este sub-slice. */
    public static function simplePathTypes(): array
    {
        return [self::Cashsale, self::Creditsale];
    }

    public function isSimplePathEligible(): bool
    {
        return in_array($this, self::simplePathTypes(), true);
    }

    public function isPosCreatable(): bool
    {
        return in_array($this, [self::Cashsale, self::Creditsale, self::Quote], true);
    }
}
