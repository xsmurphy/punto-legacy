<?php
declare(strict_types=1);

namespace Punto\Api\Wallet;

/**
 * Anular o devolver una venta de CARGA exige sacarle al bolsillo lo que esa
 * carga acreditó (context/74 §13). Si el cliente ya consumió parte, el saldo
 * no alcanza y la operación entera se rechaza: el bolsillo nunca queda
 * negativo (§3.3) y nunca se revierte parcial en silencio.
 *
 * `available` es el saldo del bolsillo leído BAJO EL LOCK; `required`, lo que
 * habría que revertir en ese bolsillo.
 */
final class WalletLoadAlreadyUsedException extends WalletException
{
    public const ERROR_CODE = 'WALLET_LOAD_USED';

    public function __construct(
        public readonly string $pocketName,
        public readonly float $available,
        public readonly float $required,
    ) {
        parent::__construct(
            'El cliente ya usó parte del saldo que se cargó con esta venta (bolsillo «' . $pocketName
            . '»), así que la carga no se puede anular ni devolver.',
            409
        );
    }

    /** @return array{errorCode: string, pocketName: string, available: float, required: float} */
    public function details(): array
    {
        return [
            'errorCode'  => self::ERROR_CODE,
            'pocketName' => $this->pocketName,
            'available'  => $this->available,
            'required'   => $this->required,
        ];
    }
}
