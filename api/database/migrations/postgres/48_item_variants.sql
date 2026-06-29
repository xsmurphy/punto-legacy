-- Variantes de producto — Phase 1
-- `item` es tabla legacy → columnas sin quotes (PG las normaliza a lowercase).
-- Eso permite que el código las escriba en camelCase sin quotes y matchee igual.
-- Ver context/08-convenciones-criticas.md §44.
-- itemParentId YA EXISTE en la tabla (usado por grupos de catálogo).
-- Los campos de variantes usan NOMBRES DISTINTOS para no colisionar.

ALTER TABLE item ADD COLUMN variantParentId UUID NULL REFERENCES item(itemid) ON DELETE RESTRICT;
ALTER TABLE item ADD COLUMN hasVariants BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE item ADD COLUMN variantAttributes JSONB;

CREATE INDEX ix_item_variant_parent ON item(companyid, variantParentId) WHERE variantParentId IS NOT NULL;
CREATE INDEX ix_item_has_variants ON item(companyid, hasVariants) WHERE hasVariants = TRUE;
