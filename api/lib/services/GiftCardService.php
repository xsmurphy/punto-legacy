<?php
/**
 * GiftCardService — consulta de gift cards del POS.
 *
 * Port de chkGiftCard (app/action.php L736) — los 2 modos JSON que usa el POS:
 *   - status (type=bool): valida la gift card para canje (deactivated/expired/used/notenough/true)
 *   - info   (type=data&json): datos para mostrar la gift card
 * La rama HTML del legacy NO se porta (el POS nunca la llama; era para screens).
 *
 * Fixes vs legacy:
 *   - userName: parametrizado (el legacy interpolaba el UUID en getValue → roto en PG).
 *   - country: se usa la constante COUNTRY (el legacy leía la tabla `setting`, que ya NO
 *     existe post-migración — los settings viven en company.config).
 *   - companyId scope en el SELECT (igual que el legacy).
 */

class GiftCardService
{
    /**
     * Valida una gift card para canje.
     * @return string  invalid|notfound|deactivated|expired|used|notenough|true
     */
    public function checkStatus(string $companyId, $rawCode, float $amount): string
    {
        if (!is_numeric($rawCode) || (int) $rawCode < 1) {
            return 'invalid';
        }
        $rec = $this->find($companyId, (int) $rawCode);
        if (!$rec) {
            return 'notfound';
        }
        if ($rec['giftCardSoldStatus'] < 1) {
            return 'deactivated';
        }
        if (strtotime($rec['giftCardSoldExpires']) < time()) {
            return 'expired';
        }
        if ($rec['giftCardSoldValue'] < 0.001) {
            return 'used';
        }
        if ($rec['giftCardSoldValue'] < $amount) {
            return 'notenough';
        }
        return 'true';
    }

    /**
     * Datos de la gift card para mostrar (modo data+json del legacy).
     * @return array|null  null si no existe.
     */
    public function getInfo(string $companyId, int $code): ?array
    {
        global $compDecimal, $compThousand;

        $rec = $this->find($companyId, $code);
        if (!$rec) {
            return null;
        }

        $expired    = strtotime($rec['giftCardSoldExpires']) < time();
        $canceled   = $rec['giftCardSoldStatus'] < 1;
        $outletName = getCurrentOutletName($rec['outletId']);

        $customerName    = 'Sin Nombre';
        $beneficiaryName = 'Sin Beneficiario';
        $beneData        = [];

        $trs = ncmExecute(
            'SELECT customerId, outletId, userId, transactionDate
               FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',
            [$rec['transactionId'], $companyId]
        );
        $trs = is_array($trs) ? $trs : [];

        if (!empty($trs['customerId'])) {
            $customerName = getCustomerName(getCustomerData($trs['customerId'], 'uid'));
        }
        if (!empty($rec['giftCardSoldBeneficiaryId'])) {
            $beneData        = getCustomerData($rec['giftCardSoldBeneficiaryId'], 'uid');
            $beneficiaryName = getCustomerName($beneData);
        }

        if ($expired) {
            $status = 'Vencida';
        } elseif ($canceled) {
            $status = 'Inhabilitada';
        } else {
            $status = CURRENCY . formatCurrentNumber($rec['giftCardSoldValue'], $compDecimal, $compThousand);
        }

        // userName parametrizado (el legacy interpolaba el UUID → roto en PG).
        $userName = '';
        if (!empty($trs['userId'])) {
            $userRow  = ncmExecute('SELECT contactName FROM contact WHERE contactId = ? LIMIT 1', [$trs['userId']]);
            $userName = is_array($userRow) ? ($userRow['contactName'] ?? '') : '';
        }

        // country desde la constante COUNTRY (la tabla `setting` ya no existe).
        $country = defined('COUNTRY') ? COUNTRY : '';

        return [
            'beneficiaryName' => $beneficiaryName,
            'customerName'    => $customerName,
            'phone'           => getPhoneFormat($beneData['phone'] ?? '', $country, 'phone_number'),
            'date'            => niceDate($trs['transactionDate'] ?? null, true),
            'outletName'      => $outletName,
            'userName'        => $userName,
            'giftCardNote'    => isBase64Decode($rec['giftCardSoldNote']),
            'amount'          => $status,
            'expires'         => niceDate($rec['giftCardSoldExpires']),
            'code'            => $code,
            'lastUsed'        => niceDate($rec['giftCardSoldLastUsed']),
            'link'            => '/screens/giftCardRedeem?s=' . base64_encode($rec['timestamp'] . ',' . $companyId),
        ];
    }

    /** Busca la gift card por código o timestamp, scopeada al tenant. */
    private function find(string $companyId, int $code)
    {
        return ncmExecute(
            'SELECT * FROM giftCardSold
              WHERE (giftCardSoldCode = ? OR timestamp = ?) AND companyId = ?
              LIMIT 1',
            [$code, $code, $companyId]
        );
    }
}
