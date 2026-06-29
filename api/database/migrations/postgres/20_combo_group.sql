-- 20_combo_group.sql
-- Combo dinámico: grupos de selección + items explícitos por grupo.
--
-- Modelo híbrido (decisión del usuario): cada grupo puede ser:
--   - sourceType='category': componentes son cualquier item de la categoría
--     referenciada en sourceCategoryId. Más flexible — agregar items a la
--     categoría los expone automáticamente en el combo.
--   - sourceType='items': componentes son los items listados en
--     combo_group_item. Permite extraPrice por item, pre-selección y orden
--     explícito.
--
-- Combo fijo NO usa estas tablas — sigue en item_compound (parent + child +
-- quantity sin grupos ni regla de precio). Solo combo_dinamico.
--
-- La regla de precio (topPrice / lowPrice / average) vive en item.data.priceRule
-- como string JSONB — no necesita tabla.

CREATE TABLE IF NOT EXISTS combo_group (
  groupId           UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  parentItemId      UUID         NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  companyId         UUID         NOT NULL REFERENCES company(companyId),
  name              VARCHAR(120) NOT NULL,
  -- 'items' (default) | 'category'
  sourceType        VARCHAR(20)  NOT NULL DEFAULT 'items',
  sourceCategoryId  UUID         REFERENCES taxonomy(taxonomyId),
  minSelection      SMALLINT     NOT NULL DEFAULT 1,
  maxSelection      SMALLINT     NOT NULL DEFAULT 1,
  sort              SMALLINT     NOT NULL DEFAULT 0,
  created_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CHECK (minSelection >= 0),
  CHECK (maxSelection >= minSelection),
  CHECK (sourceType IN ('items', 'category'))
);

CREATE INDEX IF NOT EXISTS idx_combo_group_parent  ON combo_group(parentItemId);
CREATE INDEX IF NOT EXISTS idx_combo_group_company ON combo_group(companyId);

-- Items específicos de un grupo (solo cuando sourceType='items').
CREATE TABLE IF NOT EXISTS combo_group_item (
  groupItemId    UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  groupId        UUID           NOT NULL REFERENCES combo_group(groupId) ON DELETE CASCADE,
  childItemId    UUID           NOT NULL REFERENCES item(itemId) ON DELETE RESTRICT,
  -- Modificador al precio base del combo cuando este item es seleccionado.
  -- Default 0 (el item se ofrece sin costo extra).
  extraPrice     NUMERIC(15,2)  NOT NULL DEFAULT 0,
  isPreselected  BOOLEAN        NOT NULL DEFAULT FALSE,
  sort           SMALLINT       NOT NULL DEFAULT 0,
  created_at     TIMESTAMPTZ    NOT NULL DEFAULT now(),
  UNIQUE (groupId, childItemId)
);

CREATE INDEX IF NOT EXISTS idx_combo_group_item_group ON combo_group_item(groupId);
CREATE INDEX IF NOT EXISTS idx_combo_group_item_child ON combo_group_item(childItemId);
