-- Variantes de producto — Phase 1
-- itemParentId YA EXISTE en la tabla (usado por grupos de catálogo).
-- Los campos de variantes usan NOMBRES DISTINTOS para no colisionar.

ALTER TABLE item ADD COLUMN "variantParentId" UUID NULL REFERENCES item("itemId") ON DELETE RESTRICT;
ALTER TABLE item ADD COLUMN "hasVariants" BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE item ADD COLUMN "variantAttributes" JSONB;

CREATE INDEX ix_item_variant_parent ON item("companyId", "variantParentId") WHERE "variantParentId" IS NOT NULL;
CREATE INDEX ix_item_has_variants ON item("companyId", "hasVariants") WHERE "hasVariants" = TRUE;
