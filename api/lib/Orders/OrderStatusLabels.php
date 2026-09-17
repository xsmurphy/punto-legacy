<?php
declare(strict_types=1);

namespace Punto\Api\Orders;

/**
 * Nombres de las etapas de las órdenes elegidos por el comercio.
 *
 * SOLO nombres (pedido del owner 2026-09-17): la máquina de estados
 * (`OrderCoreService::ORDER_TRANSITIONS`), la cocina, la pantalla de mozos, el
 * delivery y los reportes siguen hablando en CÓDIGOS. Esto no agrega, quita ni
 * reordena etapas — renombra las que hay.
 *
 * Vive en `company.config.orderStatusLabels` (JSON serializado, mismo trato que
 * `stockCountLists`): `{ [clave]: string }`. Una clave ausente = el nombre de
 * fábrica, que decide el front (`frontend/lib/orders/order-status-labels.ts`).
 * El backend NO conoce los nombres por defecto a propósito: si los conociera
 * habría dos copias del mapa.
 *
 * `ready_delivery` no es un estado: es el nombre de `ready` cuando la orden es
 * de envío (hoy "Enviado" de fábrica), que el comercio también puede renombrar.
 *
 * Este normalizador es el ÚNICO camino de entrada y de salida: se aplica al
 * guardar (Ajustes) y al leer (Ajustes, bootstrap del panel/caja, contexto de
 * las pantallas). Una fila vieja o escrita a mano nunca llega distinta a una
 * superficie que a otra.
 */
final class OrderStatusLabels
{
    /** Claves renombrables. Espejo de `OrderStatus` del front + el slot de envío. */
    public const KEYS = [
        'open',
        'sent',
        'in_progress',
        'ready',
        'ready_delivery',
        'out_for_delivery',
        'delivered',
        'closed',
        'cancelled',
    ];

    /** Largo máximo de un nombre. Mismo tope que el form de Ajustes. */
    public const MAX_LENGTH = 24;

    /**
     * Normaliza lo que venga (array ya decodificado, string JSON guardado, null
     * o basura) a `[clave => nombre]` con solo claves conocidas y nombres
     * válidos. Nombre vacío (tras limpiar) = la clave se omite = nombre de
     * fábrica.
     *
     * Limpieza del nombre: se sacan etiquetas HTML y los caracteres `<`/`>`
     * sueltos (es texto que se pinta en pantallas y comandas, nunca marcado),
     * los caracteres de control, se colapsan los espacios y se recorta a
     * MAX_LENGTH respetando multibyte.
     *
     * @param mixed $raw
     * @return array<string, string>
     */
    public static function normalize($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (self::KEYS as $key) {
            if (!array_key_exists($key, $raw) || !is_scalar($raw[$key])) {
                continue;
            }
            $name = self::cleanName((string) $raw[$key]);
            if ($name !== '') {
                $out[$key] = $name;
            }
        }
        return $out;
    }

    /**
     * Forma para responder JSON: un mapa vacío sale como `{}` y no como `[]`
     * (json_encode de un array PHP vacío es una lista, y el front espera un
     * objeto).
     *
     * @param mixed $raw
     * @return array<string, string>|\stdClass
     */
    public static function forJson($raw)
    {
        $labels = self::normalize($raw);
        return $labels === [] ? new \stdClass() : $labels;
    }

    private static function cleanName(string $name): string
    {
        $name = strip_tags($name);
        $name = str_replace(['<', '>'], '', $name);
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = trim($name);
        return mb_substr($name, 0, self::MAX_LENGTH);
    }
}
