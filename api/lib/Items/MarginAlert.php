<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * Alerta de margen — la aritmética, sin base de datos.
 *
 * Pedido del owner (2026-09-18): cuando una compra sube el costo de un
 * artículo, avisar cuáles quedaron por debajo del margen objetivo del comercio
 * y sugerir un precio. Es aritmética determinística, sin IA, y vive en UN solo
 * lugar: `MarginAlertService` junta los datos y esto decide.
 *
 *   margen          = (precio − costo) / precio
 *   precio sugerido = costo / (1 − objetivo), redondeado HACIA ARRIBA
 *
 * El costo es el que ya usa el sistema para el COGS (con IVA incluido —
 * decisión vigente del proyecto, ver `context/52`), y el precio es el precio
 * base del artículo. Los dos incluyen impuesto, así que la resta es
 * homogénea.
 *
 * Qué NO alerta, a propósito:
 *   - objetivo vacío o fuera de (0, 100): la alerta está apagada.
 *   - costo cero o desconocido: no hay margen que calcular (un costo que nadie
 *     cargó no es un margen del 100%, pero tampoco uno bajo).
 *   - precio cero: artículo sin precio de lista (se cotiza al vender); no hay
 *     un precio que corregir.
 *   - costo que NO subió: la alerta la dispara una suba. Un artículo que ya
 *     estaba por debajo y cuya compra no lo empeoró no se vuelve a anunciar en
 *     cada compra.
 */
final class MarginAlert
{
    /** Objetivo máximo aceptado: 100% haría el precio sugerido infinito. */
    public const MAX_TARGET = 99.0;

    /**
     * Objetivo del comercio (%) tal como vive en `settingObj.marginTarget`.
     * Devuelve null (alerta apagada) para vacío, basura o fuera de rango.
     */
    public static function parseTarget(mixed $raw): ?float
    {
        if ($raw === null || $raw === '' || is_bool($raw) || is_array($raw)) {
            return null;
        }
        $s = str_replace(',', '.', trim((string) $raw));
        if (!is_numeric($s)) {
            return null;
        }
        $v = (float) $s;
        if ($v <= 0.0 || $v > self::MAX_TARGET) {
            return null;
        }
        return round($v, 2);
    }

    /** Margen en % sobre el precio. null si el precio no es positivo. */
    public static function marginPct(float $price, float $cost): ?float
    {
        if ($price <= 0.0) {
            return null;
        }
        return (($price - $cost) / $price) * 100.0;
    }

    /**
     * Precio que deja exactamente el objetivo, redondeado hacia arriba (nunca
     * hacia abajo: redondear para abajo dejaría el precio sugerido otra vez
     * bajo el objetivo).
     */
    public static function suggestedPrice(float $cost, float $targetPct, bool $decimals): float
    {
        if ($cost <= 0.0 || $targetPct <= 0.0 || $targetPct >= 100.0) {
            return 0.0;
        }
        return self::roundUpPrice($cost / (1.0 - $targetPct / 100.0), $decimals);
    }

    /**
     * Redondeo hacia arriba a un precio "de lista": tres cifras significativas,
     * nunca por debajo de la unidad mínima de la moneda del tenant (1 sin
     * decimales, 0,01 con decimales).
     *
     *   12.345,6 (sin decimales) → 12.400
     *   950,2    (sin decimales) → 951
     *   4,5      (sin decimales) → 5
     *   45,31    (con decimales) → 45,40
     *   3,871    (con decimales) → 3,88
     *
     * No depende de la moneda ni del país: la escala sale del propio número y
     * el piso del ajuste de decimales del comercio.
     */
    public static function roundUpPrice(float $value, bool $decimals): float
    {
        if (!is_finite($value) || $value <= 0.0) {
            return 0.0;
        }
        $minor = $decimals ? 0.01 : 1.0;
        $exp   = (int) floor(log10($value));
        $step  = max(10 ** ($exp - 2), $minor);

        // El épsilon evita que 12400.0000000001 (ruido de la división) suba un
        // escalón entero.
        $units = ceil(($value / $step) - 1e-9);

        return round($units * $step, $decimals ? 2 : 0);
    }

    /**
     * Filtra y arma las filas de la alerta.
     *
     * @param list<array{itemId:string,name:string,price:float,costBefore:?float,costAfter:?float}> $rows
     * @return list<array{itemId:string,name:string,cost:float,price:float,marginPct:float,suggestedPrice:float}>
     */
    public static function evaluate(?float $targetPct, array $rows, bool $decimals): array
    {
        if ($targetPct === null) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $cost  = $r['costAfter'];
            $price = (float) $r['price'];
            if ($cost === null || $cost <= 0.0 || $price <= 0.0) {
                continue;
            }
            $before = $r['costBefore'];
            // Subió = el costo nuevo supera al anterior, con tolerancia
            // relativa para el ruido del promedio ponderado. Sin costo anterior
            // (primera compra) cuenta como suba: el costo pasó a existir.
            if ($before !== null && $cost <= $before * (1 + 1e-9)) {
                continue;
            }
            $margin = self::marginPct($price, $cost);
            if ($margin === null || $margin >= $targetPct - 1e-9) {
                continue;
            }
            $out[] = [
                'itemId'         => $r['itemId'],
                'name'           => $r['name'],
                'cost'           => round($cost, $decimals ? 2 : 0),
                'price'          => $price,
                'marginPct'      => round($margin, 1),
                'suggestedPrice' => self::suggestedPrice($cost, $targetPct, $decimals),
            ];
        }
        usort($out, static fn($a, $b) => $a['marginPct'] <=> $b['marginPct']);
        return $out;
    }
}
