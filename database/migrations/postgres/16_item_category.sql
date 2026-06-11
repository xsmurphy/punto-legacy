-- 16_item_category.sql
-- Many-to-many entre item y taxonomy (categorías).
--
-- El modelo actual usa item.categoryId (FK directa → solo una categoría).
-- Esta tabla permite N categorías por item con marca de categoría primaria.
-- item.categoryId se mantiene para compat legacy hasta Slice E.
--
-- isPrimary: la categoría "principal" del item — para reportes que necesitan
-- exactamente una (top categorías del dashboard, etc.).

CREATE TABLE IF NOT EXISTS item_category (
  itemId     UUID NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  categoryId UUID NOT NULL REFERENCES taxonomy(taxonomyId) ON DELETE CASCADE,
  isPrimary  BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (itemId, categoryId)
);

CREATE INDEX IF NOT EXISTS idx_item_category_cat ON item_category(categoryId);
CREATE INDEX IF NOT EXISTS idx_item_category_primary ON item_category(itemId) WHERE isPrimary = TRUE;

-- Backfill desde item.categoryId existente.
INSERT INTO item_category (itemId, categoryId, isPrimary)
SELECT itemId, categoryId, TRUE
FROM item
WHERE categoryId IS NOT NULL
ON CONFLICT DO NOTHING;
