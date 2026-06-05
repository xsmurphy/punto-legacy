<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Operaciones sobre gift cards del POS.
 *
 * Reemplaza las funciones globales (Slice 14 del plan PSR-4):
 *   - insertNewGiftCard(...) → GiftCard::insertNew(...)
 *
 * La lógica de deducción de saldo (manageGiftCard) migró en Slice 9
 * junto con Customer por proximidad en functions.php.
 */
final class GiftCard
{
    /**
     * Inserta una nueva gift card vendida si no existe ya (idempotente por timestamp).
     * Equivalente legacy: `insertNewGiftCard($code, $price, $expires, $trsId, $note,
     *   $beneficiaryId, $timestamp, $sendDate, $color)`.
     */
    public static function insertNew(
        mixed $code,
        mixed $price,
        mixed $expires,
        mixed $trsId,
        mixed $note,
        mixed $beneficiaryId,
        mixed $timestamp,
        mixed $sendDate,
        mixed $color
    ): mixed {
        global $db;

        $repety = ncmExecute(
            'SELECT giftCardSoldId FROM giftCardSold WHERE timestamp = ' . $timestamp . ' AND companyId = ' . COMPANY_ID . ' LIMIT 1'
        );

        if (!$repety && $timestamp) {
            $record['giftCardSoldValue']          = ($price) ? $price : 0;
            $record['giftCardSoldExpires']         = $expires;
            $record['giftCardSoldNote']            = $note;
            $record['giftCardSoldSendDate']        = $sendDate;
            $record['giftCardSoldBeneficiaryId']   = $beneficiaryId;
            $record['giftCardSoldColor']           = $color;
            $record['timestamp']                   = $timestamp;
            $record['transactionId']               = $trsId;
            $record['outletId']                    = OUTLET_ID;
            $record['companyId']                   = COMPANY_ID;

            if ($code != 'none' && $code != 'no' && $code != 'giftcard') {
                $record['giftCardSoldCode'] = $code;
            }

            return $db->AutoExecute('giftCardSold', $record, 'INSERT');
        }
    }
}
