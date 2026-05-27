<?php
/**
 * Helpers de formateo del BFF — compartidos por todos los reportes migrados.
 *
 * REGLA RAÍZ 2 (context/02-arquitectura.md): el PHP no genera markup; el BFF pre-formatea
 * los VALORES (números/fechas → strings de display) y el front arma el markup. Estos helpers
 * son el espejo de formatCurrentNumber/formatQty/niceDate del panel, pero piden el config de
 * la company a la API (bootstrap) — el BFF no toca la BD.
 */

require_once __DIR__ . '/api_client.php';

/** Config de la company (currency/decimal/thousand), cacheado por request. */
function bffConfig()
{
    static $cfg = null;
    if ($cfg === null) {
        $b   = bffApiGet('v1/bootstrap.php');
        $cfg = ($b['ok'] && is_array($b['data'])) ? $b['data'] : [];
    }
    return $cfg;
}

/** Número como string de display, respetando decimal/thousand de la company. */
function bffFormatNumber($number)
{
    $cfg     = bffConfig();
    $decimal = ($cfg['decimal'] ?? 'no') === 'no' ? 0 : 2;
    return bffNumber($number, $decimal, $cfg['thousand'] ?? 'dot');
}

/** Cantidad: enteros sin decimales, con decimales si los tiene (= formatQty del panel). */
function bffFormatQty($v)
{
    $v   = is_numeric($v) ? (float) $v : 0;
    $cfg = bffConfig();
    return bffNumber($v, (floor($v) == $v) ? 0 : 2, $cfg['thousand'] ?? 'dot');
}

/** number_format con el separador de miles de la company. */
function bffNumber($number, $decimals, $thousand)
{
    if (!is_numeric($number)) {
        $number = 0;
    }
    if ($decimals === 0) {
        $number = round($number);
    }
    return ($thousand === 'comma')
        ? number_format($number, $decimals, '.', ',')
        : number_format($number, $decimals, ',', '.');
}

/** Fecha corta de display "26 May, 2026" (= niceDate no-literal del panel). */
function bffNiceDateShort($date)
{
    static $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return 'No date';
    }
    $t = strtotime($date);
    return date('d', $t) . ' ' . $meses[(int) date('m', $t) - 1] . ', ' . date('Y', $t);
}
