-- 133_item_stock_thresholds.sql
-- Umbrales de stock por ítem: mínimo (quiebre) y máximo (sobrestock).
--
-- No existían en el modelo. Sin el mínimo, el listado no puede avisar que algo
-- está por quedarse sin stock — hay que abrir ítem por ítem y comparar a ojo.
-- Sin el máximo, no hay forma de detectar capital inmovilizado en mercadería
-- que no rota.
--
-- Columnas reales y no claves en `data`: se comparan contra el saldo en SQL
-- para ordenar y filtrar por "bajo mínimo" / "sobre máximo", y un
-- `data->>'x'` obliga a castear en cada query y no puede indexarse sin índice
-- de expresión.
--
-- NULL = umbral no definido. Es distinto de 0: en el mínimo, 0 significa
-- "avisame recién cuando llegue a cero", NULL significa "este ítem no se
-- controla por mínimo" (servicios, combos, cualquier cosa que no lleve stock).
-- Por eso las columnas son nullables y sin DEFAULT.
--
-- NUMERIC(15,3) — misma precisión que `stock.stockCount`: hay ítems que se
-- venden por peso (0.080 kg de salmón), así que los umbrales también son
-- decimales.

BEGIN;

ALTER TABLE item ADD COLUMN IF NOT EXISTS itemminstock numeric(15,3);
ALTER TABLE item ADD COLUMN IF NOT EXISTS itemmaxstock numeric(15,3);

COMMENT ON COLUMN item.itemminstock IS
  'Stock mínimo (umbral de quiebre). NULL = no se controla por mínimo (distinto de 0).';
COMMENT ON COLUMN item.itemmaxstock IS
  'Stock máximo (umbral de sobrestock). NULL = no se controla por máximo.';

-- El máximo no puede quedar por debajo del mínimo: sería una franja vacía en la
-- que el ítem estaría bajo mínimo y sobre máximo al mismo tiempo. Se valida en
-- la BD y no solo en el form — el importador de ítems y la API escriben por
-- otros caminos.
ALTER TABLE item DROP CONSTRAINT IF EXISTS item_stock_thresholds_chk;
ALTER TABLE item ADD CONSTRAINT item_stock_thresholds_chk
  CHECK (itemminstock IS NULL OR itemmaxstock IS NULL OR itemmaxstock >= itemminstock);

-- Solo los ítems que llevan stock pueden estar fuera de rango, así que el
-- índice se restringe a esos: es el filtro que va a usar el listado.
CREATE INDEX IF NOT EXISTS idx_item_stock_thresholds
  ON item (companyid, itemminstock, itemmaxstock)
  WHERE (itemminstock IS NOT NULL OR itemmaxstock IS NOT NULL)
    AND itemtrackinventory IS TRUE;

COMMIT;
