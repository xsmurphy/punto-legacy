-- 21_category.sql
-- Slice 1 del refactor taxonomy → tablas dedicadas.
--
-- Saca `category` de `taxonomy WHERE taxonomyType='category'` a su propia
-- tabla con integridad referencial real. El item_category m2m queda con
-- FK directa a category (no a taxonomy filtrado).
--
-- Approach: mismo UUID que taxonomyId (backfill INSERT...SELECT) — no se
-- migran FKs (item.categoryId, item_category.categoryId, combo_group.
-- sourceCategoryId) porque siguen apuntando al mismo UUID. Cuando los
-- consumers nuevos lean de `category` y el POS también migre, dropeamos
-- los rows viejos de taxonomy.
--
-- Dual-write durante la transición: TRIGGERS bidireccionales mantienen
-- sincronizados ambos rows usando una flag de session (`pg_temp.skip_sync`)
-- para evitar loops infinitos.

-- ── 1. Tabla nueva ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS category (
  categoryId  UUID         PRIMARY KEY,
  companyId   UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletId    UUID         REFERENCES outlet(outletId) ON DELETE SET NULL,
  name        TEXT         NOT NULL,
  parentId    UUID         REFERENCES category(categoryId) ON DELETE SET NULL,
  extra       TEXT,
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_category_company ON category(companyId);
CREATE INDEX IF NOT EXISTS idx_category_name    ON category(companyId, name);
CREATE INDEX IF NOT EXISTS idx_category_parent  ON category(parentId) WHERE parentId IS NOT NULL;

-- ── 2. Backfill desde taxonomy ────────────────────────────────────────────
-- Mismo UUID — las FKs existentes (item.categoryId, item_category.categoryId,
-- combo_group.sourceCategoryId) siguen siendo válidas porque referencian
-- taxonomy(taxonomyId) — el row de taxonomy NO se borra todavía.
INSERT INTO category (categoryId, companyId, outletId, name, parentId, extra)
SELECT
  taxonomyId,
  companyId,
  outletId,
  taxonomyName,
  sourceId,        -- sourceId = parent category UUID
  taxonomyExtra
FROM taxonomy
WHERE taxonomyType = 'category'
  AND companyId IS NOT NULL
ON CONFLICT (categoryId) DO NOTHING;

-- ── 3. Triggers de dual-write bidireccional ───────────────────────────────
-- El POS (`/app`) sigue leyendo/escribiendo en taxonomy. El panel nuevo
-- escribe en category. Ambas direcciones se sincronizan via triggers.
--
-- Para evitar loops infinitos usamos una flag de sesión:
--   SET LOCAL my.skip_taxonomy_sync = 'on';
-- antes de los INSERTs/UPDATEs disparados por el otro trigger.

-- Función: cuando se modifica `taxonomy` con type='category', sincroniza a `category`.
CREATE OR REPLACE FUNCTION sync_taxonomy_to_category() RETURNS TRIGGER AS $$
BEGIN
  -- Si la flag está set, este UPDATE viene del trigger de category → no loop.
  IF current_setting('my.skip_taxonomy_sync', true) = 'on' THEN
    RETURN NULL;
  END IF;

  PERFORM set_config('my.skip_taxonomy_sync', 'on', true);

  IF TG_OP = 'INSERT' AND NEW.taxonomyType = 'category' THEN
    INSERT INTO category (categoryId, companyId, outletId, name, parentId, extra, created_at)
    VALUES (NEW.taxonomyId, NEW.companyId, NEW.outletId, NEW.taxonomyName, NEW.sourceId, NEW.taxonomyExtra, now())
    ON CONFLICT (categoryId) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' AND NEW.taxonomyType = 'category' THEN
    UPDATE category
       SET name       = NEW.taxonomyName,
           parentId   = NEW.sourceId,
           outletId   = NEW.outletId,
           extra      = NEW.taxonomyExtra,
           updated_at = now()
     WHERE categoryId = NEW.taxonomyId;
  ELSIF TG_OP = 'DELETE' AND OLD.taxonomyType = 'category' THEN
    DELETE FROM category WHERE categoryId = OLD.taxonomyId;
  END IF;

  PERFORM set_config('my.skip_taxonomy_sync', 'off', true);
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_taxonomy_to_category ON taxonomy;
CREATE TRIGGER trg_taxonomy_to_category
AFTER INSERT OR UPDATE OR DELETE ON taxonomy
FOR EACH ROW EXECUTE FUNCTION sync_taxonomy_to_category();

-- Función inversa: cuando se modifica `category`, sincroniza a `taxonomy`.
CREATE OR REPLACE FUNCTION sync_category_to_taxonomy() RETURNS TRIGGER AS $$
BEGIN
  IF current_setting('my.skip_taxonomy_sync', true) = 'on' THEN
    RETURN NULL;
  END IF;

  PERFORM set_config('my.skip_taxonomy_sync', 'on', true);

  IF TG_OP = 'INSERT' THEN
    INSERT INTO taxonomy (taxonomyId, taxonomyName, taxonomyType, taxonomyExtra, sourceId, outletId, companyId)
    VALUES (NEW.categoryId, NEW.name, 'category', NEW.extra, NEW.parentId, NEW.outletId, NEW.companyId)
    ON CONFLICT (taxonomyId) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' THEN
    UPDATE taxonomy
       SET taxonomyName  = NEW.name,
           taxonomyExtra = NEW.extra,
           sourceId      = NEW.parentId,
           outletId      = NEW.outletId
     WHERE taxonomyId = NEW.categoryId AND taxonomyType = 'category';
  ELSIF TG_OP = 'DELETE' THEN
    DELETE FROM taxonomy WHERE taxonomyId = OLD.categoryId AND taxonomyType = 'category';
  END IF;

  PERFORM set_config('my.skip_taxonomy_sync', 'off', true);
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_category_to_taxonomy ON category;
CREATE TRIGGER trg_category_to_taxonomy
AFTER INSERT OR UPDATE OR DELETE ON category
FOR EACH ROW EXECUTE FUNCTION sync_category_to_taxonomy();

-- ── 4. NOTAS para el corte futuro ─────────────────────────────────────────
-- Cuando TODOS los consumers escriban en `category` (no en taxonomy):
--   1. DROP TRIGGER trg_taxonomy_to_category ON taxonomy;
--   2. DROP TRIGGER trg_category_to_taxonomy ON category;
--   3. DELETE FROM taxonomy WHERE taxonomyType = 'category';
--   4. (Opcional) Repoint FKs explícitas:
--        ALTER TABLE item DROP CONSTRAINT IF EXISTS fk_item_category;
--        ALTER TABLE item ADD CONSTRAINT fk_item_category
--          FOREIGN KEY (categoryId) REFERENCES category(categoryId);
--        (similar para item_category, combo_group)
--      No es necesario para correctness — solo para semantic accuracy del schema.
