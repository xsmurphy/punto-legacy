-- 113_company_slug_unique.sql
-- Settings > Empresa (2026-08-02, pedido del owner): editable desde el panel,
-- va a alimentar URLs públicas de la empresa. `company.slug` ya existe como
-- columna real (schema map de api/includes/functions.php) pero SIN índice —
-- hoy nada impide dos companies con el mismo slug.
--
-- OJO: el UNIQUE de `credit_pack.slug` (30_billing_dlocal.sql) es de OTRA
-- tabla, no protege `company`.
--
-- Índice UNIQUE parcial (no el NOT NULL/UNIQUE clásico de credit_pack) porque
-- la gran mayoría de companies existentes tiene slug NULL o '' (nunca se
-- expuso edición) — un UNIQUE normal solo falla con NULLs duplicados, pero
-- fila con slug='' sí colisionaría entre sí. Filtramos ambos casos.
--
-- Dedupe defensivo ANTES del índice: si ya hay slugs duplicados en BD,
-- conservamos el de la company más vieja (createdAt) y limpiamos ('' -> NULL)
-- las demás, para que el CREATE UNIQUE INDEX no falle.

BEGIN;

-- Identificadores SIN comillas a propósito: las columnas de `company` son
-- lowercase reales (companyid/createdat — ver mig 08, que las referencia sin
-- quotear). "companyId" quoteado buscaría una columna camelCase inexistente
-- y abortaría el boot entero del deploy.
WITH ranked AS (
  SELECT companyId,
         ROW_NUMBER() OVER (PARTITION BY slug ORDER BY createdAt ASC, companyId ASC) AS rn
    FROM company
   WHERE slug IS NOT NULL AND slug <> ''
)
UPDATE company
   SET slug = NULL
  FROM ranked
 WHERE company.companyId = ranked.companyId
   AND ranked.rn > 1;

CREATE UNIQUE INDEX IF NOT EXISTS idx_company_slug_unique
  ON company (slug)
  WHERE slug IS NOT NULL AND slug <> '';

COMMIT;
