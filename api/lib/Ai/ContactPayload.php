<?php
declare(strict_types=1);

namespace Punto\Api\Ai;

/**
 * Forma del payload de contacto que manejan las acciones `create_contact` y
 * `update_contact` del agente IA.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 *
 * La lista de campos y la regla de las coordenadas estaban implícitas, repartidas
 * entre `/v1/ai/confirm.php` (que valida) y `/v1/ai/execute.php` (que arma la
 * llamada al `ContactService`). Mientras estuvieron implícitas se desincronizaron
 * de la peor forma posible: `execute.php` armaba el payload SOLO con
 * name/type/phone/email/note, aunque `ContactService::create()` crea el contacto
 * junto con su dirección default (`mapToAddress()` mapea address, city, location,
 * lat y lng, y hay un mapa de clientes en el panel que vive de esas coordenadas).
 *
 * El costo no fue solo "no se podía": el agente le respondió al dueño que "el
 * sistema de contactos no tiene campos para dirección ni coordenadas". Presentó
 * una limitación de la ACCIÓN como una limitación del PRODUCTO, y desinformó
 * sobre su propio sistema. Con la lista acá, agregar un campo es un solo lugar y
 * las dos mitades de la operación no pueden volver a discrepar.
 *
 * La validación NO vive dentro de `confirm.php` porque ahí es intocable por un
 * arnés: ese archivo corre `bootstrap.php` y resuelve la auth en el top-level.
 * Devolviendo el mensaje en vez de llamar a `apiError()` (que hace `exit`), la
 * regla se puede ejercitar directamente — ver `api/tests/contact_address_test.php`.
 */
final class ContactPayload
{
    /**
     * Campos de la dirección default que el `ContactService` sabe manejar.
     *
     * Es el mismo juego que consume `ContactService::mapToAddress()`. `execute.php`
     * itera esta constante para armar el patch de `update_contact`, así que sumar
     * un campo soportado por el service es agregarlo acá y en el schema de
     * `frontend/lib/agent/confirm-tool.ts`.
     */
    public const ADDRESS_FIELDS = ['address', 'city', 'location', 'lat', 'lng'];

    /**
     * Valida las coordenadas de un payload de contacto.
     *
     * Las coordenadas van de a PAR: `ContactService::mapToAddress()` solo las
     * escribe si vienen lat Y lng, así que un par incompleto se descartaba en
     * silencio y el agente informaba "contacto creado con su ubicación" sobre una
     * dirección que nunca iba a aparecer en el mapa. De este mensaje sale la
     * repregunta del bot al usuario —igual que el del timbrado de la caja—, así
     * que nombra el dato que falta en los términos en que se lo va a pedir.
     *
     * @return string|null Mensaje de error legible, o null si el payload es válido
     *                     (incluido el caso "no mandó coordenadas", que es legítimo:
     *                     una dirección sin punto en el mapa se guarda igual).
     */
    public static function coordsError(array $payload): ?string
    {
        // `''` cuenta como ausente: es lo que manda un formulario vacío, y
        // `mapToAddress()` lo descarta igual con su `!empty()`.
        $hasLat = isset($payload['lat']) && $payload['lat'] !== '';
        $hasLng = isset($payload['lng']) && $payload['lng'] !== '';

        if ($hasLat !== $hasLng) {
            return 'Las coordenadas van juntas: falta ' . ($hasLat ? 'lng (longitud)' : 'lat (latitud)');
        }
        if (!$hasLat) {
            return null;
        }

        if (!is_numeric($payload['lat']) || !is_numeric($payload['lng'])) {
            return 'lat y lng deben ser números decimales (ej. -25.2867, -57.3333)';
        }

        // Rango geográfico REAL, no el que aguanta la columna. Un valor afuera es
        // un error del modelo (invirtió lat con lng, o inventó el punto), y hay
        // que cortarlo antes de emitir el confirmToken para que el usuario no
        // confirme un lote que iba a fallar. La columna `customerAddressLng`
        // aguanta el rango entero desde la mig 185 — antes topaba en ±99.99999999
        // y reventaba con cualquier longitud al oeste de -100.
        $lat = (float) $payload['lat'];
        $lng = (float) $payload['lng'];
        if ($lat < -90 || $lat > 90) {
            return 'lat fuera de rango: la latitud va de -90 a 90';
        }
        if ($lng < -180 || $lng > 180) {
            return 'lng fuera de rango: la longitud va de -180 a 180';
        }

        return null;
    }
}
