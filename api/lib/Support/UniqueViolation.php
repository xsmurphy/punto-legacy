<?php
declare(strict_types=1);

namespace Punto\Api\Support;

/**
 * Traduce la violación de un índice UNIQUE en un `\RuntimeException` con un
 * mensaje que el operador entiende.
 *
 * ── El defecto que cierra ───────────────────────────────────────────────────
 *
 * `DbQueryException extends \Exception`, NO `\RuntimeException`. Los endpoints
 * de la API envuelven las llamadas al service en `catch (\RuntimeException $e)
 * { apiError($e->getMessage(), 422); }` — así que un 23505 que sale crudo del
 * wrapper de BD ATRAVIESA ese catch y termina en un 500 genérico. El usuario
 * ve "error del servidor" ante algo que sabía explicar ("ese código ya está en
 * uso"), y el 500 ensucia GlitchTip como si fuera un bug.
 *
 * Este helper es el punto único donde ese salto de tipo se cierra. Vive en
 * `Support` (al lado de `DbQueryException`) y no en `Finance` porque el
 * problema es del stack de BD, no de un módulo: cualquier service con un
 * UNIQUE alcanzable desde un formulario lo necesita.
 *
 * ── Por qué el índice y no un pre-check ─────────────────────────────────────
 *
 * La red final es el ÍNDICE, no un `SELECT` de verificación: entre el chequeo
 * y la escritura hay una ventana de carrera. Mismo criterio que
 * `SettingsService::updateGeneral()` con el slug de la company. Un pre-check
 * sirve para el mensaje temprano; este guard sirve para que la carrera no
 * termine en 500.
 */
final class UniqueViolation
{
    /**
     * Corre $fn y traduce el 23505.
     *
     * El match por NOMBRE DE ÍNDICE va primero para poder dar un mensaje
     * específico ("ya existe un centro de costo con ese código") en vez del
     * genérico: una tabla puede tener varios UNIQUE y el operador necesita
     * saber cuál pisó.
     *
     * Lo que NO es un 23505 se re-lanza intacto: este helper traduce un caso
     * conocido, no se traga errores de BD.
     *
     * @param array<string,string> $byIndex nombre del índice → mensaje.
     * @param string $fallback mensaje ante un 23505 de un índice no listado.
     */
    public static function guard(callable $fn, array $byIndex, string $fallback)
    {
        try {
            return $fn();
        } catch (DbQueryException $e) {
            $msg = $e->getMessage();
            foreach ($byIndex as $index => $friendly) {
                if (stripos($msg, $index) !== false) {
                    throw new \RuntimeException($friendly);
                }
            }
            if ($e->sqlState() === '23505' || stripos($msg, 'duplicate key') !== false) {
                throw new \RuntimeException($fallback);
            }
            throw $e;
        }
    }
}
