<?php
declare(strict_types=1);

namespace Punto\App\Http\Response;

/**
 * Helper de cierre de request — escribe output, cierra DB y termina.
 *
 * Reemplaza la función global (Slice 2 del plan PSR-4):
 *   - dai($val, $noclose)  → Output::dai($val, $noclose)
 *
 * La función global permanece como wrapper que delega acá — cero breaking
 * changes en los ~542 callsites (es la utilidad de cierre más usada del POS).
 *
 * Side effect crítico: cierra la conexión DB (\$GLOBALS['db']) salvo que
 * el caller pase $noclose=true. Esto previene leaks de conexiones cuando
 * el endpoint termina en `dai()` en vez de retornar normalmente.
 */
final class Output
{
    /**
     * Escribe $val al output buffer, cierra DB y termina la request.
     * Equivalente legacy: `dai($val, $noclose)`
     */
    public static function dai(string $val = '', bool $noclose = false): never
    {
        if (!$noclose && isset($GLOBALS['db']) && $GLOBALS['db']) {
            $GLOBALS['db']->Close();
        }
        die($val);
    }
}
