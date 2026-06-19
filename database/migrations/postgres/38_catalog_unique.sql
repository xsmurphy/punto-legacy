-- 38_catalog_unique.sql
-- UNIQUE (companyId, lower(name)) en taxonomy, category, brand, tax.
-- Requiere que mig 37 haya corrido — si falla acá es que hay duplicados nuevos.
--
-- taxonomy: partial — globales (companyId IS NULL) quedan fuera del scope.

BEGIN;

CREATE UNIQUE INDEX IF NOT EXISTS uq_taxonomy_company_type_name
  ON taxonomy (companyId, taxonomyType, LOWER(taxonomyName))
  WHERE companyId IS NOT NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uq_category_company_name
  ON category (companyId, LOWER(name));

CREATE UNIQUE INDEX IF NOT EXISTS uq_brand_company_name
  ON brand (companyId, LOWER(name));

CREATE UNIQUE INDEX IF NOT EXISTS uq_tax_company_name
  ON tax (companyId, LOWER(name));

COMMIT;
