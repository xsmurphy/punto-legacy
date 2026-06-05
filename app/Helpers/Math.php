<?php
declare(strict_types=1);

namespace Punto\App\Helpers;

/**
 * Helpers de matemática del POS.
 *
 * Reemplaza las funciones globales (Slice 6 del plan PSR-4):
 *   - divider($a, $b, $force, $round)  → Math::divide($a, $b, $force, $round)
 *   - rounder($v, $round)              → Math::round($v, $round)
 *   - rester($a, $b, $round)           → Math::diff($a, $b, $round)
 *
 * Las funciones globales permanecen como wrappers que delegan acá — cero
 * breaking changes en los ~56 callsites totales del POS:
 *   - divider:  50 callers
 *   - rester:    3 callers
 *   - rounder:   0 callers externos (interno: divider, rester, niceDate)
 *
 * NOTA: NO son funciones de money path crítico (formatCurrentNumber,
 * addTax, etc. quedan separadas en Slice 12 `App\Domain\Money`). Estas
 * son utilities matemáticas defensivas (división con guards, rounding
 * configurable, resta sin negativos).
 */
final class Math
{
    /**
     * División defensiva: devuelve 0 si cualquier operando ≤ 0.
     * Sin `$force`: invierte si $b < $a (proportional asymmetric).
     * Con `$force=true`: división literal $a / $b siempre.
     *
     * Equivalente legacy: `divider($val1, $val2, $force, $round)`.
     *
     * @param mixed $a, $b   Numéricos. ≤0 → 0.
     * @param bool $force    Si true, división literal sin swap.
     * @param string|false $round  Pasado a Math::round().
     */
    public static function divide(mixed $a, mixed $b, bool $force = false, string|false $round = false): int|float
    {
        if ($a > 0 && $b > 0) {
            if ($force) {
                return self::round($a / $b, $round);
            }
            $out = $a > $b ? $a / $b : $b / $a;
        } else {
            $out = 0;
        }
        return self::round($out, $round);
    }

    /**
     * Redondea según modo: 'down'/floor, 'up'/ceil, 'auto'/round, false/passthrough.
     * Equivalente legacy: `rounder($value, $round)`.
     */
    public static function round(int|float $value, string|false $round = false): int|float
    {
        if (!$round) {
            return $value;
        }
        return match ($round) {
            'down'  => floor($value),
            'up'    => ceil($value),
            'auto'  => round($value),
            default => $value,
        };
    }

    /**
     * Diferencia absoluta entre dos números (siempre ≥ 0).
     * Equivalente legacy: `rester($first, $second, $round)`.
     */
    public static function diff(int|float $a, int|float $b, string|false $round = false): int|float
    {
        $out = abs($a - $b);
        return self::round($out, $round);
    }
}
