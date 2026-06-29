-- 07_item_jsonb_demote.sql
-- Degrada columnas descriptivas estáticas de `item` al JSONB `data`.
-- Columnas: itemImage, itemTaxExcluded, itemDiscount, itemUOM
--
-- Criterio (decisión 2026-05-25, invariante #6 de 04-modelo-de-dominio.md): solo van
-- como columnas reales los campos indexables o usados en cálculos SQL. Estas 4 tienen
-- 0 uso en WHERE/ORDER/JOIN/GROUP/SUM (auditado por grep), no tienen índice ni FK:
--   · itemImage       — flag bool de presencia de imagen; solo lecturas PHP (!empty).
--   · itemTaxExcluded — columna fantasma: 0 lecturas y 0 escrituras en todo el repo.
--   · itemDiscount    — descuento fijo; solo cálculo PHP (precio * disc / 100), no SQL.
--   · itemUOM         — unidad de medida (texto descriptivo).
-- itemPrice e itemCost SE QUEDAN (se usan en agregaciones SQL — SUM/AVG). itemName/itemSKU
-- (indexados/buscados), itemSort/itemStatus/itemType (indexados) y las FKs también quedan.
--
-- ⚠️ DEPLOY COORDINADO — esta migración DEBE correr junto con los cambios de código:
--   1. _getTableSchema() 'item' (panel/includes/functions.php): quitadas del whitelist
--      `columns` (así ncmInsert/ncmUpdate las enrutan a `data` automáticamente).
--   2. Writers crudos migrados:
--        panel/a_items.php:2986 (children de bulkUpdate)  → ncmUpdate (rutea a data)
--        app/includes/functions.php:4224 (seed de signup)  → se quitó itemImage del record
--      (el ncmInsert/ncmUpdate de app/ NO rutea a JSONB; por eso el writer de app no puede
--       pasar columnas degradadas — AutoExecute crashea con columna inexistente.)
--   3. Readers con SELECT explícito de estas columnas → alias data->>'..' o + `data`:
--        panel/a_items.php:23,67 (búsqueda)         → data->>'itemUOM' AS itemUOM
--        panel/a_items.php:457 (compound)           → data->>'itemUOM' AS itemUOM
--        panel/a_bulk_production.php:108            → data->>'itemUOM' AS itemUOM
--        panel/lib/items/ItemRepository.php:68      → + data (flatten por fila)
--        panel/API/get_items.php:116                → + data (flatten por fila)
--   4. Readers SELECT* sin flatten (forceObj / $db->Execute crudo) → _flattenJsonb():
--        panel/a_items.php:3752 (render de lista), panel/inventory.php:51 (editform),
--        panel/API/get_items.php (loop principal), app/fetch.php:584, app/fetchs.php:725
--   Los readers vía ncmExecute single-row (getItemData, editform a_items.php:322, etc.)
--   YA aplanan — no requieren cambio.
--
-- Razón del orden atómico: `_flattenJsonb` hace que la columna real GANE sobre el JSONB.
-- Si se recorta el whitelist sin dropear la columna, las lecturas devuelven el valor
-- viejo de la columna en vez del nuevo del JSONB → updates rotos. Trim + DROP van juntos.
--
-- Backfill no-destructivo: NULLIF evita guardar defaults (false / 0 / '') → mantiene
-- `data` liviano (la mayoría de items no tienen imagen ni descuento). jsonb_strip_nulls
-- descarta las claves nulas. Booleano → JSON boolean (json_decode da bool PHP, !empty OK).
--
-- ⚠️ PRIVILEGIOS: el ALTER TABLE ... DROP COLUMN requiere ser OWNER de la tabla. El
-- usuario de app (POSTGRES_USER=punto) NO es owner → falla con "must be owner of table
-- item". Correr como el rol dueño/superuser (en local: el OS user de Postgres.app). El
-- backfill (UPDATE) sí lo puede correr el app-user.

BEGIN;

-- 1. Backfill: mover los valores significativos a `data` (merge no destructivo).
UPDATE item
SET data = COALESCE(data, '{}'::jsonb) || jsonb_strip_nulls(jsonb_build_object(
        'itemImage',       NULLIF(itemImage, false),
        'itemTaxExcluded', NULLIF(itemTaxExcluded, 0),
        'itemDiscount',    NULLIF(itemDiscount, 0),
        'itemUOM',         NULLIF(itemUOM, '')
    ))
WHERE itemImage = true
   OR (itemTaxExcluded IS NOT NULL AND itemTaxExcluded <> 0)
   OR (itemDiscount    IS NOT NULL AND itemDiscount    <> 0)
   OR (itemUOM         IS NOT NULL AND itemUOM         <> '');

-- 2. Drop de las columnas degradadas (idempotente).
ALTER TABLE item DROP COLUMN IF EXISTS itemImage;
ALTER TABLE item DROP COLUMN IF EXISTS itemTaxExcluded;
ALTER TABLE item DROP COLUMN IF EXISTS itemDiscount;
ALTER TABLE item DROP COLUMN IF EXISTS itemUOM;

COMMIT;
