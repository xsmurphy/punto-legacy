-- 140_pos_order_item_addons.sql
-- Add-ons en la ORDEN (context/41, primer gap abierto de F5). Hasta acá los
-- add-ons solo existían en la VENTA (`itemSold`, F3): la orden los perdía al
-- crearse, así que la comanda de cocina imprimía "Hamburguesa" a secas y el
-- cocinero no sabía que iba sin cebolla. Este es el caso de uso PRINCIPAL de
-- la feature en gastronomía, y es también lo que desbloquea la D4 del plan
-- (variantes de bloque de impresión — NO se implementa acá).
--
-- Jerarquía REAL, no meta: `parentorderitemid` apunta a la LÍNEA padre
-- (`pos_order_item.orderitemid`), no al ítem del catálogo. Es la diferencia
-- con `itemSold.itemsoldparent`, cuya FK es a `item(itemid)` y obligó a F3 a
-- guardar el link a la línea exacta dentro de `meta.addon.parentItemSoldId`.
-- Acá no hay esa restricción y se modela derecho: ON DELETE CASCADE — borrar
-- la línea padre se lleva sus hijas, nunca quedan add-ons huérfanos
-- apuntando a una línea que no existe.
--
-- `addonoptionid` NO lleva FK a `addon_group_option` A PROPÓSITO. El panel
-- edita los grupos con AddonService::replaceForItem, que BORRA las filas de
-- opciones y las reinserta con ids nuevos: una FK con CASCADE borraría
-- líneas de órdenes históricas al reordenar un grupo, y una FK con RESTRICT
-- bloquearía editar la ficha de cualquier producto ya ordenado. La columna
-- es trazabilidad ("qué opción del catálogo era") para los reportes de F6;
-- el dato que sobrevive por sí solo es `name` + `pricedelta`, ya
-- desnormalizados en la fila.
--
-- `pricedelta` es el recargo UNITARIO de la hija (por unidad de la propia
-- línea hija), espejo de `price`: el importe de la línea es qty × pricedelta.
-- 0 = la opción no suma (D2 del plan).
--
-- ⚠ INVARIANTE DE PLATA — leer antes de sumar esta columna en cualquier
-- query. `pricedelta` NO se agrega a los totales de la orden y NINGUNA de las
-- agregaciones existentes (SUM(qty*price) en Reports/OrdersService,
-- TransactionDetailService, SpaceBalanceService y SpaceSettlementService) se
-- tocó. El motivo: el recargo YA ESTÁ dentro del `price` de la línea PADRE —
-- `CartLine.unitPrice` se arma como `item.price + addonsDelta(selections)`
-- (ver el docblock de CartLine.selections en frontend/lib/cart/store.ts) y el
-- POS manda ese precio como `price` del ítem de la orden. Sumar además el
-- `pricedelta` de las hijas cobraría el add-on DOS VECES. Por eso las hijas
-- nacen con `price = 0`: son desglose para la cocina y trazabilidad, no
-- plata. La plata de la orden sigue viviendo, exactamente como hoy, en la
-- línea padre.
--
-- Todo lowercase sin comillas: `pos_order_item` es tabla del módulo de
-- órdenes (mig 79), cuyo estilo son identificadores sin quotes — mismo
-- criterio que la mig 135 (`tags JSONB`). Las tablas camelCase QUOTED de la
-- mig 134 son otra convención (context/08 §44) y no aplica acá.
--
-- Idempotente (corre en cada boot, patrón migs 94/96/97): IF NOT EXISTS en
-- las tres columnas y en el índice. Todo NULLABLE y sin default: las órdenes
-- existentes no tienen hijas, no se reescribe una sola fila y el boot no se
-- bloquea.

BEGIN;

-- La línea padre de este add-on. NULL = línea normal (todas las órdenes
-- existentes). CASCADE: la hija no sobrevive a su padre.
ALTER TABLE pos_order_item
    ADD COLUMN IF NOT EXISTS parentorderitemid UUID
        REFERENCES pos_order_item(orderitemid) ON DELETE CASCADE;

-- Qué opción del catálogo se eligió (trazabilidad para reportes, F6).
-- Sin FK a propósito — ver cabecera.
ALTER TABLE pos_order_item
    ADD COLUMN IF NOT EXISTS addonoptionid UUID;

-- Recargo unitario de la hija. 0 = la opción no suma (D2). NO es plata que
-- se sume a los totales — ver la invariante de la cabecera.
ALTER TABLE pos_order_item
    ADD COLUMN IF NOT EXISTS pricedelta NUMERIC(14,2);

-- Traer las hijas de una línea/orden. Parcial: la enorme mayoría de las
-- filas son líneas normales (NULL) y no aportan nada al índice.
CREATE INDEX IF NOT EXISTS idx_pos_order_item_parent
    ON pos_order_item (parentorderitemid)
    WHERE parentorderitemid IS NOT NULL;

COMMIT;
