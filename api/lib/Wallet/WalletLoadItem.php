<?php
declare(strict_types=1);

namespace Punto\Api\Wallet;

/**
 * El ítem de sistema "Carga de saldo" (context/74 F2, mig 234).
 *
 * La carga es una VENTA (modo A, §4): su línea necesita un ítem para que la
 * factura, el detalle de la transacción y los reportes por producto la
 * nombren. Es UNO por comercio y lo administra Punto:
 *
 *   - `item.systemkey = 'wallet_load'` (índice único por comercio).
 *   - Servicio sin stock (`itemKind = 'servicio'`, sin control de inventario):
 *     una carga no saca nada de la góndola.
 *   - SIN filas en `item_outlet`: invisible para toda caja por construcción
 *     (mig 170), así que el cajero no lo puede agregar suelto — la única
 *     puerta es la acción "Cargar saldo", que manda la línea con `walletLoad`.
 *   - Sin impuesto propio: la tasa de cada carga es la del BOLSILLO
 *     (`wallet_pocket.taxid`), congelada en la línea por `SaleService`.
 *   - El panel no lo lista ni lo deja editar/archivar/borrar
 *     (`ItemService::isSystemItem`).
 *
 * El POS NO conoce su id: manda la línea sin ítem y el servidor lo resuelve.
 * Así una caja recién pareada, o un comercio que todavía nunca cargó saldo,
 * puede emitir su primera carga sin red (D15).
 */
final class WalletLoadItem
{
    public const SYSTEM_KEY = 'wallet_load';
    public const NAME       = 'Carga de saldo';

    /**
     * Id del ítem de carga del comercio; lo crea si no existe.
     *
     * Idempotente ante carreras: dos ventas que lo crean a la vez chocan en
     * `uidx_item_systemkey` y la segunda no hace nada (`ON CONFLICT DO
     * NOTHING`) — las dos terminan leyendo la MISMA fila.
     */
    public static function ensure(string $companyId): string
    {
        $found = self::find($companyId);
        if ($found !== null) {
            return $found;
        }

        global $db;
        $db->Execute(
            "INSERT INTO item
                 (itemid, itemname, itemtype, itemkind, itemstatus, itemcansale,
                  itemtrackinventory, itemprice, companyid, systemkey, data)
             VALUES (gen_random_uuid(), ?, 'product', 'servicio', 1, TRUE,
                     FALSE, 0, ?, ?, '{\"itemTaxIncluded\": true}'::jsonb)
             ON CONFLICT (companyid, systemkey) WHERE systemkey IS NOT NULL DO NOTHING",
            [self::NAME, $companyId, self::SYSTEM_KEY]
        );

        $found = self::find($companyId);
        if ($found === null) {
            throw new \RuntimeException('No se pudo preparar el ítem de carga de saldo');
        }
        return $found;
    }

    public static function find(string $companyId): ?string
    {
        global $db;
        $rs = $db->Execute(
            'SELECT itemid FROM item WHERE companyid = ? AND systemkey = ? LIMIT 1',
            [$companyId, self::SYSTEM_KEY]
        );
        return ($rs && !$rs->EOF) ? (string) $rs->fields['itemid'] : null;
    }
}
