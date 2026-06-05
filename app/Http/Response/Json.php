<?php
declare(strict_types=1);

namespace Punto\App\Http\Response;

/**
 * Respuestas JSON del POS. Punto de salida unificado para endpoints legacy.
 *
 * Reemplaza las funciones globales (Slice 2 del plan PSR-4):
 *   - jsonDieResult($array, $code)           → Json::send($array, $code)
 *   - jsonDieMsg($msg, $code, $type)         → Json::die($msg, $code, $type)
 *
 * Las funciones globales permanecen como wrappers que delegan acá —
 * cero breaking changes en los ~219 callsites (61 jsonDieMsg + 158 jsonDieResult).
 *
 * Convención del POS:
 *   - status code DEFAULT de errores es 401 (legacy quirk: "treat as auth fail")
 *   - el wrapper de mensaje envuelve en `{type: msg}` (type='error' por default)
 *   - die() después de echo — no hay flushing por buffer-aware (lo hace dai())
 */
final class Json
{
    /**
     * Envía un array como JSON y termina la request.
     * Equivalente legacy: `jsonDieResult($array, $code)`
     */
    public static function send(mixed $payload, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        die(json_encode($payload));
    }

    /**
     * Envía un mensaje JSON envuelto en `{type: msg}` y termina la request.
     * Equivalente legacy: `jsonDieMsg($msg, $code, $type)`
     *
     * NOTA: el DEFAULT del status code es 401 (heredado del legacy). Si el caller
     * no pasa $code, la respuesta se interpreta como auth fail. Para 200 success
     * pasar `Json::die($msg, 200, 'success')`.
     */
    public static function die(string $msg = 'true', int $code = 401, string $type = 'error'): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        die(json_encode([$type => $msg]));
    }
}
