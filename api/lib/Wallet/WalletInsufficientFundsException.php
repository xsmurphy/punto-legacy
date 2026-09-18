<?php
declare(strict_types=1);

namespace Punto\Api\Wallet;

/**
 * El bolsillo no alcanza: la operación dejaría el saldo negativo, y el tope ES
 * el saldo (context/74 §3.3). No se escribió nada.
 *
 * Lleva cuánto HABÍA en el bolsillo en el momento del rechazo, leído bajo el
 * mismo lock que el chequeo. Es lo que necesita la caja (F2, D6) para cobrar la
 * diferencia con otro medio en la misma venta: debitar `available` del
 * bolsillo y el resto por otro lado — el bolsillo llega a cero y no más.
 */
final class WalletInsufficientFundsException extends WalletException
{
    public function __construct(
        public readonly float $available,
        public readonly float $requested,
    ) {
        parent::__construct('El saldo del bolsillo no alcanza', 409);
    }
}
