-- 220_item_barcode.sql
-- Código de barras propio del artículo, SEPARADO del SKU.
--
-- ── Por qué una columna y no el JSONB ───────────────────────────────────────
--
-- El scanner del POS (`frontend/hooks/use-barcode-scanner.ts`, wiring en
-- `components/register/cart-panel.tsx`) matcheaba contra `sku` o `itemId` y
-- nada más. El único lugar donde el comercio podía cargar un código de barras
-- era la matriz de VARIANTES, que lo escribía como `data->>'itemBarcode'`
-- (JSONB suelto, `VariantService::bulkUpsertVariants()`): un dato que no
-- viajaba en el SELECT del catálogo, que el POS nunca veía y contra el que
-- ningún escaneo podía pegar. En la práctica el código de barras existía en la
-- BD y era invisible para la caja.
--
-- Columna real (no una clave más en `data`) porque:
--   1. El escaneo se resuelve por IGUALDAD sobre miles de ítems — eso quiere
--      un índice, y un índice sobre una expresión JSONB no lo usa el filtro
--      que el catálogo necesita.
--   2. `Schema::split()` (el routing de `ncmInsert`/`ncmUpdate`) decide
--      columna-vs-JSONB contra el catálogo REAL de Postgres: en cuanto la
--      columna existe, TODOS los write-paths que ya mandan `barcode` en el
--      record la escriben sin tocar un solo caller. Mismo mecanismo que
--      arregló hasVariants/variantParentId en la mig 99.
--
-- ── Backfill ────────────────────────────────────────────────────────────────
--
-- Se promueve `data->>'itemBarcode'` a la columna Y se borra la clave del
-- JSONB — mismo criterio que la mig 99: dos fuentes de verdad para el mismo
-- dato terminan divergiendo, y `_flattenJsonb()` aplana `data` a top-level, o
-- sea que la clave vieja seguiría apareciendo en el shape del ítem al lado de
-- la columna nueva. Desde acá `itemBarcode` NO se escribe más (ver el docblock
-- de `VariantService::bulkUpsertVariants()`).
--
-- OJO: NO usar el operador `?` de jsonb en nada que pase por PDO — colisiona
-- con el placeholder y aborta el boot (incidente de las migs 74/77). Acá
-- alcanza con `IS NOT NULL` sobre `data->>'itemBarcode'`, que además descarta
-- solo el caso que importa.
--
-- ── Sin UNIQUE, a propósito ─────────────────────────────────────────────────
--
-- Decisión del owner: dos artículos pueden compartir el mismo código y gana el
-- primero que encuentra el catálogo. Un UNIQUE (companyid, barcode) rechazaría
-- el alta/edición del segundo ítem —y en un comercio real los códigos
-- repetidos existen (packs rearmados, proveedores que reciclan EAN)—, así que
-- el costo de bloquear la carga es mayor que el de resolver por el primero.
-- El índice es de BÚSQUEDA, no de unicidad.
BEGIN;

ALTER TABLE item ADD COLUMN IF NOT EXISTS barcode VARCHAR(255);

UPDATE item
   SET barcode = data->>'itemBarcode',
       data    = data - 'itemBarcode'
 WHERE data->>'itemBarcode' IS NOT NULL
   AND data->>'itemBarcode' <> ''
   AND barcode IS NULL;

-- Las filas que tenían la clave VACÍA ('' o null dentro del JSONB) no aportan
-- un código: se limpia la clave igual para no dejar basura que reaparezca
-- aplanada en el shape del ítem.
UPDATE item
   SET data = data - 'itemBarcode'
 WHERE data->>'itemBarcode' IS NOT NULL
   AND data->>'itemBarcode' = '';

-- Parcial: el 99% del catálogo no tiene código de barras y esas filas no
-- tienen por qué engordar el índice ni su mantenimiento en cada UPDATE.
CREATE INDEX IF NOT EXISTS idx_item_barcode
    ON item (companyid, barcode)
 WHERE barcode IS NOT NULL;

COMMIT;
