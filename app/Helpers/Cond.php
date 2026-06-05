<?php
declare(strict_types=1);

namespace Punto\App\Helpers;

/**
 * Helpers condicionales / null-coalesce custom del POS.
 *
 * Reemplaza la función global (Slice 6 del plan PSR-4):
 *   - iftn($if, $else, $then)  → Cond::iftn($if, $else, $then)
 *
 * El wrapper global permanece — cero breaking changes en los ~778 callsites
 * (la 3er función más usada del módulo después de validity y validateHttp).
 *
 * NOTA: el patrón `iftn` es un ternary defensivo del POS — distinto de `??`
 * porque usa `Validation::isValid()` (que rechaza '', 0, 'undefined', etc.)
 * en vez del isset/null check de PHP. Se mantiene la lógica VERBATIM porque
 * cada caller depende de ese comportamiento específico (especialmente el
 * tratamiento de la string literal 'undefined' que llega del front JS).
 */
final class Cond
{
    /**
     * Ternary basado en `Validation::isValid()`:
     *   - Si $if es válido → retorna $then (o $if si $then no es válido).
     *   - Si $if NO es válido → retorna $else (o '' si $else no es válido).
     *
     * Equivalente legacy: `iftn($if, $else, $then)`.
     *
     * Pattern de uso típico en el POS:
     *   iftn($x)                     // ≈ $x ?: ''           (default '')
     *   iftn($x, 'fallback')         // ≈ $x ?: 'fallback'
     *   iftn($x, 'fallback', 'val')  // si $x válido → 'val', sino 'fallback'
     */
    public static function iftn(mixed $if, mixed $else = false, mixed $then = false): mixed
    {
        $elseFinal = Validation::isValid($else) ? $else : '';
        $thenFinal = Validation::isValid($then) ? $then : $if;
        return Validation::isValid($if) ? $thenFinal : $elseFinal;
    }
}
