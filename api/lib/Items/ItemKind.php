<?php
declare(strict_types=1);

namespace Punto\Api\Items;

/**
 * Tipo canónico de ítem (`item.itemKind`, mig 15) y su mapeo a los flags legacy.
 *
 * `itemKind` es NOT NULL sin default: TODO INSERT en `item` tiene que
 * declararlo. El mapa vivía como función global dentro de `api/v1/items.php`,
 * así que el resto del backend no podía reusarlo — y SignupService, que crea los
 * ítems demo del alta, lo omitía. Resultado: el INSERT violaba el NOT NULL, la
 * transacción quedaba envenenada y el registro moría con un 25P02 (reporte del
 * tester 2026-08-04). Vive acá para que el siguiente caller no lo vuelva a
 * omitir.
 *
 * Los flags legacy (itemType / itemCanSale / itemTrackInventory /
 * itemProduction) se mantienen en dual-write: el POS y el panel legacy los
 * siguen leyendo hasta Slice E.
 */
final class ItemKind
{
    /** Kind por defecto de un producto vendible común. */
    public const DEFAULT = 'producto';

    /** @var array<string,array<string,mixed>> */
    private const MAP = [
        'producto'           => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 0],
        'insumo_stock'       => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 1, 'itemProduction' => 0],
        'insumo_sin_stock'   => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'insumo_control'     => ['itemType' => 'product',    'itemCanSale' => 0, 'itemTrackInventory' => 1, 'itemProduction' => 0],
        'produccion_directa' => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'produccion_previa'  => ['itemType' => 'production', 'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 1],
        'servicio'           => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'servicio_sesiones'  => ['itemType' => 'product',    'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'combo_fijo'         => ['itemType' => 'combo',      'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'combo_dinamico'     => ['itemType' => 'combo',      'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'descuento'          => ['itemType' => 'discount',   'itemCanSale' => 1, 'itemTrackInventory' => 0, 'itemProduction' => 0],
        'giftcard'           => ['itemType' => 'giftcard',   'itemCanSale' => 1, 'itemTrackInventory' => 1, 'itemProduction' => 0],
    ];

    /**
     * Flags legacy de un kind. Devuelve [] si el kind no existe — mismo
     * comportamiento que la función global que reemplaza.
     *
     * @return array<string,mixed>
     */
    public static function legacyFlags(string $kind): array
    {
        return self::MAP[$kind] ?? [];
    }

    /**
     * Record listo para un INSERT en `item`: el kind más sus flags legacy.
     * Es la forma correcta de crear un ítem desde cualquier servicio — evita
     * que se escriba el kind sin los flags, o los flags sin el kind.
     *
     * @return array<string,mixed>
     */
    public static function insertRecord(string $kind = self::DEFAULT): array
    {
        return array_merge(['itemKind' => $kind], self::legacyFlags($kind));
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }
}
