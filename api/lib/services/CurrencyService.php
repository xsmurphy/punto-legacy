<?php
/**
 * CurrencyService — tasas de cambio configuradas del tenant (Slice 12).
 *
 * Lógica portada de app/action.php:
 *   setCurrencies (L79) — lista de monedas extranjeras con su tasa, para mostrar el
 *   total de la venta convertido en el POS.
 *
 * No toca BD: computa sobre globals cargados por data.php/head.php:
 *   - $_fullSettings['currencies'] — tasas configuradas por el tenant (settingObj)
 *   - $_COUNTRIES_H                — catálogo de países/monedas (libraries/countries.php)
 *   - COUNTRY                      — país del tenant (se excluye de la lista)
 */

class CurrencyService
{
    /**
     * Lista de monedas extranjeras con su tasa de cambio configurada.
     *
     * @return array<int, array{ccode:string, code:string, value:float}>
     *   ccode = código de país (ISO), code = código de moneda, value = tasa (0 si no configurada).
     */
    public function exchangeList(): array
    {
        global $_fullSettings, $_COUNTRIES_H;

        $rates = $_fullSettings['currencies'] ?? null;
        $home  = defined('COUNTRY') ? COUNTRY : null;
        $out   = [];

        foreach (($_COUNTRIES_H ?? []) as $ccode => $value) {
            $currency = $value['currency']['code'] ?? null;
            if ($currency === null || $currency === $home) {
                continue;
            }

            $rate = 0.0;
            if (is_array($rates)) {
                foreach ($rates as $entry) {
                    if (isset($entry[$currency]) && $entry[$currency] > 0) {
                        $rate = (float) $entry[$currency];
                    }
                }
            }

            $out[] = ['ccode' => $ccode, 'code' => $currency, 'value' => $rate];
        }

        return $out;
    }
}
