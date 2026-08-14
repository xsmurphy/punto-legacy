<?php
declare(strict_types=1);

namespace Punto\App\Domain;

/**
 * Formateo y sanitización de valores monetarios del POS.
 *
 * Reemplaza las funciones globales (Slice 12 del plan PSR-4):
 *   - formatCurrentNumber($number, $de, $ts)          → Money::formatNumber(...)
 *   - formatQty($val, $extDec)                         → Money::formatQty(...)
 *   - addTax($tax, $price)                             → Money::addTax(...)
 *   - formatNumberToInsertDB($n, $force, $decimals)    → Money::formatForDB(...)
 *   - forceExtraDecimalsNumber($num, $max)             → Money::forceDecimals(...)
 *   - paymentMObjSanitizer($array)                     → Money::sanitizePaymentObj(...)
 *   - saleArraySanitizer($array)                       → Money::sanitizeSaleArray(...)
 *
 * CRÍTICO: formatCurrentNumber tiene 530 callers. Semántica preservada VERBATIM —
 * usa los globales $DECIMAL y $THOUSAND_SEPARATOR para determinar el formato.
 *
 * Nota: formatQty pasa un 4.° argumento a formatCurrentNumber que la función
 * ignora (solo acepta 3 parámetros). Este comportamiento se preserva verbatim.
 */
final class Money
{
    /**
     * Formatea un número al display format configurado (puntos/comas según config).
     * Equivalente legacy: `formatCurrentNumber($number, $de, $ts)`.
     *
     * @param mixed  $de  Override de DECIMAL ('yes'/'no' o '').
     * @param mixed  $ts  Override de THOUSAND_SEPARATOR ('comma'/'dot' o '').
     */
    public static function formatNumber(mixed $number, mixed $de = '', mixed $ts = ''): string
    {
        global $DECIMAL, $THOUSAND_SEPARATOR;

        $number = (float) $number;
        if (!$number) {
            $number = 0;
        }

        $decimal = (!$de) ? $DECIMAL : $de;
        $thouS   = (!$ts) ? $THOUSAND_SEPARATOR : $ts;

        if ($decimal == 'no') {
            $number = round($number);
            if ($thouS == 'comma') {
                return number_format($number, 0, '.', ',');
            } else {
                return number_format($number, 0, ',', '.');
            }
        } else {
            if ($thouS == 'comma') {
                return number_format($number, 2, '.', ',');
            } else {
                return number_format($number, 2, ',', '.');
            }
        }
    }

    /**
     * Formatea una cantidad: sin decimales si es entero; con decimales si los tiene.
     * Equivalente legacy: `formatQty($val, $extDec)`.
     *
     * Nota: el 4.° arg pasado a formatCurrentNumber (extDec) era silenciosamente
     * ignorado — la función solo acepta 3 params. Comportamiento verbatim preservado.
     */
    public static function formatQty(mixed $val, int $extDec = 2): string
    {
        if (strpos($val . '', '.') === false) {
            return self::formatNumber($val, 'no', false);
        }

        $getDec = explode('.', $val);
        if ($getDec[1] > 0) {
            return self::formatNumber($val, 'yes', false);
        }

        return self::formatNumber($val, 'no', false);
    }

    /**
     * Calcula el monto de impuesto incluido en un precio.
     * Equivalente legacy: `addTax($tax, $price)`.
     */
    public static function addTax(mixed $tax, mixed $price): int|float
    {
        if ($tax && $price && $tax > 0) {
            $taxVal = $price / (1 + ($tax / 100));
            $total  = $price - $taxVal;

            if ($total && $total > 0) {
                return $total;
            }

            return 0;
        }

        return 0;
    }

    /**
     * Normaliza un número formateado al formato DB (punto decimal, sin separadores).
     * Equivalente legacy: `formatNumberToInsertDB($number, $forceDecimals, $decimalsCount)`.
     */
    public static function formatForDB(mixed $number, bool $forceDecimals = false, int $decimalsCount = 2): mixed
    {
        if (!validity($number)) {
            $number = 0;
        }

        if (DECIMAL == 'no' && !$forceDecimals) {
            if (THOUSAND_SEPARATOR == 'dot') {
                $explode = explode(',', $number);
                $number  = $explode[0];
                $number  = str_replace('.', '', $number);
            } else {
                $explode = explode('.', $number);
                $number  = $explode[0];
                $number  = str_replace(',', '', $number);
            }
            return $number;
        }

        if (THOUSAND_SEPARATOR == 'dot') {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } else {
            $number = str_replace(',', '', $number);
        }

        return self::forceDecimals($number, $decimalsCount);
    }

    /**
     * Fuerza un número a tener exactamente $max decimales (sin separador de miles).
     * Equivalente legacy: `forceExtraDecimalsNumber($num, $max)`.
     */
    public static function forceDecimals(mixed $num, int $max = 3): string
    {
        return number_format($num, $max, '.', '');
    }

    /**
     * Sanitiza un array de métodos de pago para persistencia.
     * Equivalente legacy: `paymentMObjSanitizer($array)`.
     */
    public static function sanitizePaymentObj(mixed $array): array|false
    {
        $out = [];
        $i   = 0;

        if (!validity($array)) {
            return false;
        }

        foreach ($array as $key => $value) {
            if ($i > 10) {
                break;
            }

            $type = $value['type'];
            if (is_numeric($type)) {
                $type = intval($type);
            } else {
                $type = markupt2HTML(['text' => $type, 'type' => 'HtM']);
            }

            $name  = markupt2HTML(['text' => $value['name'], 'type' => 'HtM']);
            $price = floatval($value['price']);
            $total = floatval($value['total']);
            $extra = markupt2HTML(['text' => $value['extra'], 'type' => 'HtM']);
            $name  = substr($name, 0, 20);
            $extra = substr($extra, 0, 30);

            $out[] = ['type' => $type, 'name' => $name, 'price' => $price, 'total' => $total, 'extra' => $extra];
            $i++;
        }

        return $out;
    }

    /**
     * Sanitiza un array de ítems de venta (incluyendo gift cards) para persistencia.
     * Equivalente legacy: `saleArraySanitizer($array)`.
     */
    public static function sanitizeSaleArray(mixed $array): array
    {
        $out = [];
        $i   = 0;

        foreach ($array as $key => $value) {
            $tags = [];
            if (array_key_exists('tags', $value) && is_array($value['tags'])) {
                foreach ($value['tags'] as $k) {
                    $tags[] = markupt2HTML(['text' => $k, 'type' => 'HtM']);
                }
            }

            if ($value['type'] == 'giftcard') {
                $out[] = [
                    'itemId'        => markupt2HTML(['text' => $value['itemId'], 'type' => 'HtM']),
                    'count'         => floatval($value['count']),
                    'oQty'          => floatval($value['oQty']),
                    'uniPrice'      => floatval($value['uniPrice']),
                    'price'         => floatval($value['price']),
                    'total'         => floatval($value['total']),
                    'tax'           => floatval($value['tax']),
                    'user'          => markupt2HTML(['text' => $value['user'], 'type' => 'HtM']),
                    'type'          => markupt2HTML(['text' => $value['type'], 'type' => 'HtM']),
                    'date'          => markupt2HTML(['text' => $value['date'], 'type' => 'HtM']),
                    'note'          => markupt2HTML(['text' => $value['note'], 'type' => 'HtM']),
                    'beneficiaryId' => markupt2HTML(['text' => $value['beneficiaryId'], 'type' => 'HtM']),
                    'giftDate'      => markupt2HTML(['text' => $value['giftDate'], 'type' => 'HtM']),
                    'giftcardColor' => markupt2HTML(['text' => $value['giftcardColor'], 'type' => 'HtM']),
                    'giftcardExp'   => markupt2HTML(['text' => $value['giftcardExp'], 'type' => 'HtM']),
                    'giftcardId'    => (int) $value['giftcardId'],
                    'uId'           => (int) $value['uId'],
                    'currency'      => markupt2HTML(['text' => $value['currency'], 'type' => 'HtM']),
                ];
            } else {
                // Emisión de gift card (item kind=giftcard vendido desde el POS nuevo,
                // NO la rama legacy `type==='giftcard'` de arriba). El item SÍ tiene
                // itemId (es un item de catálogo real) — la metadata opcional viaja en
                // `giftcard` y SaleService la usa para crear la fila en `giftcard`.
                // Whitelist explícita: nunca pasar el objeto crudo del front.
                $giftcardMeta = null;
                if (array_key_exists('giftcard', $value) && is_array($value['giftcard'])) {
                    $gc = $value['giftcard'];
                    $giftcardMeta = [
                        'code'                 => markupt2HTML(['text' => (string) ($gc['code'] ?? ''), 'type' => 'HtM']),
                        'beneficiaryContactId' => markupt2HTML(['text' => (string) ($gc['beneficiaryContactId'] ?? ''), 'type' => 'HtM']),
                        'expiresAt'            => markupt2HTML(['text' => (string) ($gc['expiresAt'] ?? ''), 'type' => 'HtM']),
                        'note'                 => markupt2HTML(['text' => (string) ($gc['note'] ?? ''), 'type' => 'HtM']),
                    ];
                }

                // Canje de vale (F2, context/36-vouchers-plan.md) — presente solo en
                // líneas que trajo applyVoucher() en el carrito. Whitelist explícita,
                // mismo criterio que giftcardMeta: nunca pasar el objeto crudo del
                // front. SaleService::persistVoucherRedemptions() la usa para llamar
                // VoucherService::consume() dentro de la transacción de la venta.
                $voucherMeta = null;
                if (array_key_exists('voucher', $value) && is_array($value['voucher'])) {
                    $vo = $value['voucher'];
                    $voucherMeta = [
                        'voucherId' => markupt2HTML(['text' => (string) ($vo['voucherId'] ?? ''), 'type' => 'HtM']),
                        'code'      => markupt2HTML(['text' => (string) ($vo['code'] ?? ''), 'type' => 'HtM']),
                    ];
                }

                // Add-ons elegidos en la línea (F3, context/41). Whitelist
                // explícita, mismo criterio que giftcardMeta/voucherMeta: nunca
                // pasar el objeto crudo del front. Acá SOLO viajan optionId+qty:
                // el PRECIO lo recalcula SaleService::expandAddonSelections desde
                // `addon_group_option.priceDelta` en BD, nunca del cliente.
                $selections = null;
                if (array_key_exists('selections', $value) && is_array($value['selections'])) {
                    $selections = [];
                    foreach ($value['selections'] as $sel) {
                        if (!is_array($sel)) {
                            continue;
                        }
                        $selections[] = [
                            'optionId' => markupt2HTML(['text' => (string) ($sel['optionId'] ?? ''), 'type' => 'HtM']),
                            'qty'      => (int) ($sel['qty'] ?? 1),
                        ];
                    }
                }

                $line = [
                    'itemId'        => markupt2HTML(['text' => $value['itemId'], 'type' => 'HtM']),
                    'count'         => floatval($value['count']),
                    'oQty'          => floatval($value['oQty'] ?? 0),
                    'name'          => markupt2HTML(['text' => $value['name'], 'type' => 'HtM']),
                    'uniPrice'      => floatval($value['uniPrice'] ?? 0),
                    'price'         => floatval($value['price'] ?? 0),
                    'total'         => floatval($value['total'] ?? 0),
                    'tax'           => floatval($value['tax'] ?? 0),
                    'discount'      => floatval(array_key_exists('discount', $value) ? $value['discount'] : 0),
                    'totalDiscount' => floatval(array_key_exists('totalDiscount', $value) ? $value['totalDiscount'] : 0),
                    'tags'          => $tags,
                    'user'          => markupt2HTML(['text' => $value['user'] ?? '', 'type' => 'HtM']),
                    'type'          => markupt2HTML(['text' => $value['type'], 'type' => 'HtM']),
                    'date'          => markupt2HTML(['text' => array_key_exists('date', $value) ? $value['date'] : '', 'type' => 'HtM']),
                    'note'          => markupt2HTML(['text' => array_key_exists('note', $value) ? $value['note'] : '', 'type' => 'HtM']),
                    'currency'      => markupt2HTML(['text' => array_key_exists('currency', $value) ? $value['currency'] : '', 'type' => 'HtM']),
                    'uId'           => (int) array_key_exists('uId', $value) ? $value['uId'] : 0,
                    'parent'        => array_key_exists('parent', $value) ? ($value['parent'] ? (int) $value['parent'] : null) : null,
                    'isParent'      => array_key_exists('isParent', $value) ? ($value['isParent'] ? (int) $value['isParent'] : null) : null,
                    'giftcard'      => $giftcardMeta,
                    'voucher'       => $voucherMeta,
                ];

                // La key SOLO existe si el cliente la mandó: una línea sin
                // `selections` produce EXACTAMENTE el mismo array que antes de
                // F3 (el POS actual no las manda y su venta no cambia en nada,
                // ni siquiera en el JSON que se persiste en
                // `transaction.meta.transactionDetails`). Se distingue a
                // propósito "no mandó nada" (sin key → la venta ni consulta los
                // grupos) de "mandó lista vacía" (con key → se valida min/max y
                // se inyectan los add-ons fijos).
                if ($selections !== null) {
                    $line['selections'] = $selections;
                }

                $out[] = $line;
            }

            $i++;
        }

        return $out;
    }
}
