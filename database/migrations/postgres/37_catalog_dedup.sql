-- 37_catalog_dedup.sql
-- Mergea duplicados de taxonomy por (companyId, taxonomyType, lower(taxonomyName)).
-- Pre-requisito para mig 38 (UNIQUE). IRREVERSIBLE.
--
-- Patrón: CTE con canonicalId = MIN(taxonomyId) por grupo. Reasigna todas
-- las FKs (item.{tax,brand,category,location}Id, outlet.{tax,category}Id,
-- toCategory.categoryId, item_category.categoryId, item_brand.brandId,
-- item.data.tags JSONB array). Luego DELETE de duplicados — los triggers
-- bidireccionales (migs 21/22/23) propagan a category/brand/tax.

BEGIN;

-- 1. Tabla temporal: para cada (companyId, type, lower(name)) con dups, mapear
--    cada duplicateId al canonicalId (MIN).
CREATE TEMP TABLE _dedup_map AS
SELECT
  t.taxonomyId        AS duplicate_id,
  canonical.canon_id  AS canonical_id,
  t.taxonomyType      AS taxonomy_type
FROM taxonomy t
JOIN (
  SELECT companyId, taxonomyType, LOWER(taxonomyName) AS lname,
         MIN(taxonomyId::text)::uuid AS canon_id
  FROM taxonomy
  WHERE companyId IS NOT NULL
  GROUP BY companyId, taxonomyType, LOWER(taxonomyName)
  HAVING COUNT(*) > 1
) canonical
  ON canonical.companyId    = t.companyId
 AND canonical.taxonomyType = t.taxonomyType
 AND canonical.lname        = LOWER(t.taxonomyName)
WHERE t.taxonomyId <> canonical.canon_id;

CREATE INDEX ON _dedup_map(duplicate_id);

-- 2. Reasignar FKs simples en item (escalares).
UPDATE item i SET categoryId = m.canonical_id
  FROM _dedup_map m WHERE i.categoryId = m.duplicate_id;
UPDATE item i SET brandId = m.canonical_id
  FROM _dedup_map m WHERE i.brandId = m.duplicate_id;
UPDATE item i SET taxId = m.canonical_id
  FROM _dedup_map m WHERE i.taxId = m.duplicate_id;
UPDATE item i SET locationId = m.canonical_id
  FROM _dedup_map m WHERE i.locationId = m.duplicate_id;

-- 3. Reasignar FKs en outlet.
UPDATE outlet o SET taxId = m.canonical_id
  FROM _dedup_map m WHERE o.taxId = m.duplicate_id;
UPDATE outlet o SET categoryId = m.canonical_id
  FROM _dedup_map m WHERE o.categoryId = m.duplicate_id;

-- 4. Reasignar FK en toCategory.
UPDATE toCategory tc SET categoryId = m.canonical_id
  FROM _dedup_map m WHERE tc.categoryId = m.duplicate_id;

-- 5. m2m item_category — DELETE filas que chocarían (item ya tiene canonical),
--    luego UPDATE las que no.
DELETE FROM item_category ic
USING _dedup_map m
WHERE ic.categoryId = m.duplicate_id
  AND EXISTS (
    SELECT 1 FROM item_category ic2
     WHERE ic2.itemId = ic.itemId AND ic2.categoryId = m.canonical_id
  );
UPDATE item_category ic SET categoryId = m.canonical_id
  FROM _dedup_map m WHERE ic.categoryId = m.duplicate_id;

-- 6. m2m item_brand — mismo patrón.
DELETE FROM item_brand ib
USING _dedup_map m
WHERE ib.brandId = m.duplicate_id
  AND EXISTS (
    SELECT 1 FROM item_brand ib2
     WHERE ib2.itemId = ib.itemId AND ib2.brandId = m.canonical_id
  );
UPDATE item_brand ib SET brandId = m.canonical_id
  FROM _dedup_map m WHERE ib.brandId = m.duplicate_id;

-- 7. item.data.tags JSONB array (UUIDs). Para cada item con tags que incluya
--    al menos un duplicate_id, reemplazar los duplicates por canonicals y
--    deduplicar el array resultante.
UPDATE item
   SET data = jsonb_set(
     data,
     '{tags}',
     (
       SELECT COALESCE(jsonb_agg(DISTINCT mapped_tag), '[]'::jsonb)
       FROM (
         SELECT COALESCE(m.canonical_id::text, t.tag) AS mapped_tag
         FROM jsonb_array_elements_text(data->'tags') AS t(tag)
         LEFT JOIN _dedup_map m
           ON m.duplicate_id::text = t.tag
           AND m.taxonomy_type = 'tag'
       ) sub
     )
   )
 WHERE jsonb_typeof(data->'tags') = 'array'
   AND EXISTS (
     SELECT 1 FROM jsonb_array_elements_text(data->'tags') AS t(tag)
     JOIN _dedup_map m
       ON m.duplicate_id::text = t.tag
       AND m.taxonomy_type = 'tag'
   );

-- 8. Borrar duplicados de taxonomy. Los triggers bidireccionales propagan a
--    category/brand/tax. Las filas en item_category/item_brand ya fueron
--    reasignadas en pasos 5/6.
DELETE FROM taxonomy WHERE taxonomyId IN (SELECT duplicate_id FROM _dedup_map);

DROP TABLE _dedup_map;

COMMIT;
