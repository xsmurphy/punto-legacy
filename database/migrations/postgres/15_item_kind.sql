-- 15_item_kind.sql
-- Agrega columna canónica `itemKind` a la tabla `item`.
--
-- El kind reemplaza el sistema de 4 flags (itemType + itemCanSale +
-- itemTrackInventory + itemProduction) como fuente de verdad del tipo de item.
-- Los flags legacy se MANTIENEN para compat con el POS y panel legacy durante
-- la transición. Se eliminan en Slice E cuando el legacy muera.
--
-- Backfill: inferencia one-time desde el estado actual de los flags.
-- Items que no matcheen ningún case → 'producto' (fallback seguro).
-- Nota: insumo_control / servicio_sesiones / produccion_directa / combo_dinamico
-- no se pueden inferir sin inspección manual; se asigna el tipo más cercano
-- y el operador los corrige desde el panel cuando sea necesario.
-- En producción real no hay datos todavía (proyecto en desarrollo).

ALTER TABLE item ADD COLUMN IF NOT EXISTS itemKind VARCHAR(30);

-- itemProduction / itemCanSale / itemTrackInventory son BOOLEAN — PG no
-- permite comparar bool con int (`> 0`). Usar IS TRUE / IS FALSE.
UPDATE item SET itemKind = CASE
  WHEN itemType = 'discount'                                THEN 'descuento'
  WHEN itemType = 'giftcard'                                THEN 'giftcard'
  WHEN itemType = 'combo'                                   THEN 'combo_fijo'
  WHEN itemType = 'production' OR itemProduction IS TRUE    THEN 'produccion_previa'
  WHEN itemCanSale IS FALSE AND itemTrackInventory IS TRUE  THEN 'insumo_stock'
  WHEN itemCanSale IS FALSE AND itemTrackInventory IS FALSE THEN 'insumo_sin_stock'
  WHEN itemCanSale IS TRUE  AND itemTrackInventory IS TRUE  THEN 'producto'
  WHEN itemCanSale IS TRUE  AND itemTrackInventory IS FALSE THEN 'servicio'
  ELSE 'producto'
END
WHERE itemKind IS NULL;

ALTER TABLE item ALTER COLUMN itemKind SET NOT NULL;

ALTER TABLE item DROP CONSTRAINT IF EXISTS item_kind_valid;
ALTER TABLE item ADD CONSTRAINT item_kind_valid CHECK (
  itemKind IN (
    'producto', 'insumo_stock', 'insumo_sin_stock', 'insumo_control',
    'produccion_directa', 'produccion_previa',
    'servicio', 'servicio_sesiones',
    'combo_fijo', 'combo_dinamico',
    'descuento', 'giftcard'
  )
);

CREATE INDEX IF NOT EXISTS idx_item_kind ON item(itemKind);
CREATE INDEX IF NOT EXISTS idx_item_kind_company ON item(companyId, itemKind);
