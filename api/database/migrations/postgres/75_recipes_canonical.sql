BEGIN;

-- ============================================================
-- Producción F0 — recetas canónicas en item_compound.
-- Ver context/23-production-module-plan.md (plan cerrado).
--
-- Problema: hay dos tablas de recetas. `toCompound` (legacy) es la que lee
-- el negocio (venta, COGS, void de venta) vía Inventory::getCompoundsArray.
-- `item_compound` (mig 19) es la que escribe el editor del panel vía
-- ItemCompoundService. El editor escribe la nueva, el negocio lee la
-- vieja -> las recetas editadas hoy NO afectan stock ni costos reales.
--
-- Esta migración copia toCompound -> item_compound (una sola vez, no
-- destructiva) para que ambas convivan con los mismos datos hasta que el
-- código switchee a leer item_compound exclusivamente (próximo commit).
--
-- Mapeo de columnas (confirmado por código, NO por nombre):
--   toCompound.itemId       (parent/combo)      -> item_compound.parentItemId
--   toCompound.compoundId   (child/ingrediente)  -> item_compound.childItemId
--   toCompound.toCompoundQty                     -> item_compound.quantity
--   toCompound.toCompoundOrder                   -> item_compound.sort
--   companyId sale del item padre (item.companyid WHERE itemid = toCompound.itemid)
--
-- Exclusiones (no son "receta" en el sentido de item_compound):
--   - toCompoundQty <= 0            (fila inválida / vacía)
--   - toCompoundPreselected IS NOT NULL  (semántica de combo-picker: el
--     usuario elige 1 de N ingredientes en runtime, no es un ingrediente
--     fijo de receta con cantidad determinada)
--
-- Idempotente: ON CONFLICT (parentitemid, childitemid) DO NOTHING — no pisa
-- ediciones ya hechas en item_compound vía el editor. toCompound NO se toca
-- (ni se borra ni se modifica), queda de solo-lectura histórica.
-- ============================================================

INSERT INTO item_compound (compoundid, parentitemid, childitemid, quantity, sort, companyid)
SELECT
    gen_random_uuid(),
    tc.itemid,
    tc.compoundid,
    tc.tocompoundqty,
    COALESCE(tc.tocompoundorder, 0),
    p.companyid
FROM tocompound tc
JOIN item p ON p.itemid = tc.itemid
WHERE tc.tocompoundqty > 0
  AND tc.tocompoundpreselected IS NULL
  AND tc.itemid IS NOT NULL
  AND tc.compoundid IS NOT NULL
  -- El child debe existir y pertenecer a la misma company que el padre
  -- (item_compound.childitemid tiene FK a item; filas huérfanas del
  -- legacy no deben migrar y romper la migración entera).
  AND EXISTS (
    SELECT 1 FROM item c WHERE c.itemid = tc.compoundid AND c.companyid = p.companyid
  )
ON CONFLICT (parentitemid, childitemid) DO NOTHING;

COMMIT;
