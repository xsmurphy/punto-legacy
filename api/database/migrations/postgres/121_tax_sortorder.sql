-- 121_tax_sortorder.sql
-- Orden manual de impuestos (drag&drop en Settings → Catálogo → Impuestos).
--
-- `sortOrder` es columna real (no JSON en `extra`) siguiendo el precedente de
-- rate/kind (mig 120): columnas exclusivas de `tax`, sin contraparte en
-- `taxonomy` — los triggers bidireccionales de la mig 23 no la sincronizan y
-- no hace falta: los lectores legacy de taxonomy no ordenan por ella.
--
-- NULL = sin orden asignado; el ORDER BY usa NULLS LAST con fallback a name,
-- así los tenants que nunca reordenaron mantienen el orden alfabético actual.
-- Idempotente: re-correr no falla (IF NOT EXISTS).

BEGIN;

ALTER TABLE tax ADD COLUMN IF NOT EXISTS sortOrder INT;

COMMIT;
