-- 39_tag.sql
-- Slice 4 del refactor taxonomy → tablas dedicadas.
-- Saca `tag` de taxonomy(type='tag') a tabla dedicada + m2m item_tag.
-- data.tags JSONB se mantiene durante transición (lectura legacy) pero
-- item_tag es la fuente de verdad nueva.

BEGIN;

-- 1. Tabla tag (mismo UUID que taxonomy)
CREATE TABLE IF NOT EXISTS tag (
  tagId       UUID         PRIMARY KEY,
  companyId   UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletId    UUID         REFERENCES outlet(outletId) ON DELETE SET NULL,
  name        TEXT         NOT NULL,
  extra       TEXT,
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_tag_company ON tag(companyId);
CREATE UNIQUE INDEX IF NOT EXISTS uq_tag_company_name ON tag (companyId, LOWER(name));

-- 2. Backfill desde taxonomy
INSERT INTO tag (tagId, companyId, outletId, name, extra)
SELECT taxonomyId, companyId, outletId, taxonomyName, taxonomyExtra
FROM taxonomy
WHERE taxonomyType = 'tag'
  AND companyId IS NOT NULL
ON CONFLICT (tagId) DO NOTHING;

-- 3. m2m item_tag
CREATE TABLE IF NOT EXISTS item_tag (
  itemId UUID NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  tagId  UUID NOT NULL REFERENCES tag(tagId) ON DELETE CASCADE,
  PRIMARY KEY (itemId, tagId)
);

CREATE INDEX IF NOT EXISTS idx_item_tag_tag ON item_tag(tagId);

-- 4. Backfill desde item.data.tags (JSONB array de UUIDs)
INSERT INTO item_tag (itemId, tagId)
SELECT i.itemId, (t.tag)::uuid
FROM item i
CROSS JOIN LATERAL jsonb_array_elements_text(i.data->'tags') AS t(tag)
WHERE jsonb_typeof(i.data->'tags') = 'array'
  AND t.tag ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
  AND EXISTS (SELECT 1 FROM tag WHERE tagId = (t.tag)::uuid)
ON CONFLICT DO NOTHING;

-- 5. Triggers bidireccionales (mismo patrón mig 22)
CREATE OR REPLACE FUNCTION sync_taxonomy_to_tag() RETURNS TRIGGER AS $$
BEGIN
  IF current_setting('my.skip_tag_sync', true) = 'on' THEN
    RETURN NULL;
  END IF;

  PERFORM set_config('my.skip_tag_sync', 'on', true);

  IF TG_OP = 'INSERT' AND NEW.taxonomyType = 'tag' THEN
    INSERT INTO tag (tagId, companyId, outletId, name, extra, created_at)
    VALUES (NEW.taxonomyId, NEW.companyId, NEW.outletId, NEW.taxonomyName, NEW.taxonomyExtra, now())
    ON CONFLICT (tagId) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' AND NEW.taxonomyType = 'tag' THEN
    UPDATE tag
       SET name       = NEW.taxonomyName,
           outletId   = NEW.outletId,
           extra      = NEW.taxonomyExtra,
           updated_at = now()
     WHERE tagId = NEW.taxonomyId;
  ELSIF TG_OP = 'DELETE' AND OLD.taxonomyType = 'tag' THEN
    DELETE FROM tag WHERE tagId = OLD.taxonomyId;
  END IF;

  PERFORM set_config('my.skip_tag_sync', 'off', true);
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_taxonomy_to_tag ON taxonomy;
CREATE TRIGGER trg_taxonomy_to_tag
AFTER INSERT OR UPDATE OR DELETE ON taxonomy
FOR EACH ROW EXECUTE FUNCTION sync_taxonomy_to_tag();

CREATE OR REPLACE FUNCTION sync_tag_to_taxonomy() RETURNS TRIGGER AS $$
BEGIN
  IF current_setting('my.skip_tag_sync', true) = 'on' THEN
    RETURN NULL;
  END IF;

  PERFORM set_config('my.skip_tag_sync', 'on', true);

  IF TG_OP = 'INSERT' THEN
    INSERT INTO taxonomy (taxonomyId, taxonomyName, taxonomyType, taxonomyExtra, outletId, companyId)
    VALUES (NEW.tagId, NEW.name, 'tag', NEW.extra, NEW.outletId, NEW.companyId)
    ON CONFLICT (taxonomyId) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' THEN
    UPDATE taxonomy
       SET taxonomyName  = NEW.name,
           taxonomyExtra = NEW.extra,
           outletId      = NEW.outletId
     WHERE taxonomyId = NEW.tagId AND taxonomyType = 'tag';
  ELSIF TG_OP = 'DELETE' THEN
    DELETE FROM taxonomy WHERE taxonomyId = OLD.tagId AND taxonomyType = 'tag';
  END IF;

  PERFORM set_config('my.skip_tag_sync', 'off', true);
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_tag_to_taxonomy ON tag;
CREATE TRIGGER trg_tag_to_taxonomy
AFTER INSERT OR UPDATE OR DELETE ON tag
FOR EACH ROW EXECUTE FUNCTION sync_tag_to_taxonomy();

COMMIT;
