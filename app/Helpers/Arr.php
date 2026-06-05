<?php
declare(strict_types=1);

namespace Punto\App\Helpers;

/**
 * Helpers defensivos de array/string del POS.
 *
 * Reemplaza las funciones globales (Slice 6 del plan PSR-4):
 *   - counts($v)                         → Arr::sizeOf($v)
 *   - arrKey($a, $k, $returnOnFalse)     → Arr::getKey($a, $k, $default)
 *   - explodes($sep, $arr, $return=-1)   → Arr::safeExplode($sep, $arr, $return)
 *   - implodes($sep, $arr, $returnEmpty) → Arr::safeImplode($sep, $arr, $returnEmpty)
 *
 * Las funciones globales permanecen como wrappers que delegan acá — cero
 * breaking changes en los ~205 callsites totales del POS:
 *   - explodes:  134 callers
 *   - implodes:   36 callers
 *   - counts:     34 callers (también caller interno de Validation::isValid)
 *   - arrKey:      1 caller
 *
 * NOTA: el nombre `Arr` sigue convención Laravel — corto, no confunde con
 * `array` (typecast/built-in). `counts()` queda acá aunque acepte string
 * porque devuelve "longitud polimórfica" (strlen para strings, count para
 * arrays) — semántica de tamaño defensivo.
 */
final class Arr
{
    /**
     * Tamaño polimórfico de un valor: numeric→ese valor, string→strlen, array→count, sino 0.
     * Equivalente legacy: `counts($val)`.
     *
     * USADO INTERNAMENTE por `Validation::isValid()` para distinguir valores "vacíos".
     * NO cambiar la semántica sin alinear con Slice 3.
     */
    public static function sizeOf(mixed $val): int|float
    {
        if (is_numeric($val)) {
            return $val;
        }
        if (is_string($val)) {
            return strlen($val);
        }
        if (is_array($val)) {
            return count($val);
        }
        return 0;
    }

    /**
     * Lectura segura de array key: devuelve el valor si existe, sino $default.
     * Equivalente legacy: `arrKey($array, $key, $returnOnFalse)`.
     *
     * NOTA: legacy usa `iftn($returnOnFalse, false)` para el fallback —
     * preserva exactamente esa semántica (false → false, '' → false, etc.).
     */
    public static function getKey(array $array, string|int $key, mixed $default = false): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }
        return Cond::iftn($default, false);
    }

    /**
     * `explode()` defensivo: si el input no es string válido, devuelve [] o ''.
     * Con `$return >= 0` retorna ese índice del array resultante.
     *
     * Equivalente legacy: `explodes($separator, $array, $return)`.
     */
    public static function safeExplode(string $separator, mixed $input, int $return = -1): mixed
    {
        if (Validation::isValid($input, 'string')) {
            $parts = explode($separator, (string) $input);
            if ($return > -1) {
                return $parts[$return] ?? '';
            }
            return $parts;
        }
        // Input inválido: '' si pidió índice, sino [] (paridad legacy).
        return $return > -1 ? '' : [];
    }

    /**
     * `implode()` defensivo: si $array no es array válido, devuelve false o ''.
     * Equivalente legacy: `implodes($separator, $array, $returnEmpty)`.
     */
    public static function safeImplode(string $separator, mixed $array, bool $returnEmpty = false): string|false
    {
        if (is_array($array) && Validation::isValid($array)) {
            return implode($separator, $array);
        }
        return $returnEmpty ? '' : false;
    }
}
