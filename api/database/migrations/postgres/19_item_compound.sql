-- 19_item_compound.sql
-- Recetas / compuestos: cada item de producción tiene N ingredientes con cantidad.
--
-- Antes el legacy guardaba ingredientes como items hijo (con data.parent_id =
-- parentItemId). Crear un row de item por cada ingrediente es costoso y mezcla
-- conceptos. La join table separa identidad del ingrediente (childItemId apunta
-- al item real — típicamente un insumo) de la relación con el padre.
--
-- ON DELETE CASCADE en parent → si se borra el item padre, sus compounds desaparecen.
-- ON DELETE RESTRICT en child → no se puede borrar un insumo que está siendo usado
-- en una receta (forzamos al user a sacarlo de las recetas primero).
--
-- UNIQUE (parentItemId, childItemId): cada ingrediente aparece UNA vez por padre.
-- Si querés sumar más cantidad, editás el row existente — no creás duplicados.

CREATE TABLE IF NOT EXISTS item_compound (
  compoundId    UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  parentItemId  UUID           NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  childItemId   UUID           NOT NULL REFERENCES item(itemId) ON DELETE RESTRICT,
  quantity      NUMERIC(15,4)  NOT NULL DEFAULT 1,
  sort          SMALLINT       NOT NULL DEFAULT 0,
  companyId     UUID           NOT NULL REFERENCES company(companyId),
  created_at    TIMESTAMPTZ    NOT NULL DEFAULT now(),
  UNIQUE (parentItemId, childItemId)
);

CREATE INDEX IF NOT EXISTS idx_compound_parent  ON item_compound(parentItemId);
CREATE INDEX IF NOT EXISTS idx_compound_child   ON item_compound(childItemId);
CREATE INDEX IF NOT EXISTS idx_compound_company ON item_compound(companyId);
