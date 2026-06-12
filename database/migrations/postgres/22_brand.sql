-- 22_brand.sql
-- Slice 2 del refactor taxonomy → tablas dedicadas.
--
-- Saca `brand` de `taxonomy WHERE taxonomyType='brand'` a su propia tabla.
-- A diferencia de `category` (que ya tenía item_category m2m), aquí
-- CREAMOS un m2m nuevo `item_brand` porque el modelo legacy tenía
-- item.brandId como FK 1:1 — pero un producto puede ser de varias marcas
-- (distribuidores, multimarca, etc.).
--
-- Misma estrategia que Slice 1: mismo UUID, triggers bidireccionales con
-- flag de sesión anti-loop.

-- ── 1. Tabla brand ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS brand (
  brandId     UUID         PRIMARY KEY,
  companyId   UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletId    UUID         REFERENCES outlet(outletId) ON DELETE SET NULL,
  name        TEXT         NOT NULL,
  extra       TEXT,
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_brand_company ON brand(companyId);
CREATE INDEX IF NOT EXISTS idx_brand_name    ON brand(companyId, name);

-- ── 2. Backfill desde taxonomy ────────────────────────────────────────────
INSERT INTO brand (brandId, companyId, outletId, name, extra)
SELECT taxonomyId, companyId, outletId, taxonomyName, taxonomyExtra
FROM taxonomy
WHERE taxonomyType = 'brand'
  AND companyId IS NOT NULL
ON CONFLICT (brandId) DO NOTHING;

-- ── 3. m2m item_brand ────────────────────────────────────────────────────
-- Reemplaza el modelo legacy item.brandId (1:1) por N marcas por item con
-- la marca "principal" marcada (mismo patrón que item_category.isPrimary).
CREATE TABLE IF NOT EXISTS item_brand (
  itemId    UUID NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  brandId   UUID NOT NULL REFERENCES brand(brandId) ON DELETE CASCADE,
  isPrimary BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (itemId, brandId)
);

CREATE INDEX IF NOT EXISTS idx_item_brand_brand   ON item_brand(brandId);
CREATE INDEX IF NOT EXISTS idx_item_brand_primary ON item_brand(itemId) WHERE isPrimary = TRUE;

-- Backfill desde item.brandId existente. Mantiene compat: item.brandId
-- (FK directa) sigue siendo el "brand primary" hasta que el frontend nuevo
-- exponga UI multi-brand.
INSERT INTO item_brand (itemId, brandId, isPrimary)
SELECT itemId, brandId, TRUE
FROM item
WHERE brandId IS NOT NULL
ON CONFLICT DO NOTHING;

-- ── 4. Triggers de dual-write bidireccional ───────────────────────────────
-- Misma estrategia que category — flag de sesión anti-loop, separada
-- (`my.skip_brand_sync`) para no interferir entre tipos.

CREATE OR REPLACE FUNCTION sync_taxonomy_to_brand() RETURNS TRIGGER AS $$
BEGIN
  IF current_setting('my.skip_brand_sync', true) = 'on' THEN
    RETURN NULL;
  END IF;

  PERFORM set_config('my.skip_brand_sync', 'on', true);

  IF TG_OP = 'INSERT' AND NEW.taxonomyType = 'brand' THEN
    INSERT INTO brand (brandId, companyId, outletId, name, extra, created_at)
    VALUES (NEW.taxonomyId, NEW.companyId, NEW.outletId, NEW.taxonomyName, NEW.taxonomyExtra, now())
    ON CONFLICT (brandId) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' AND NEW.taxonomyType = 'brand' THEN
    UPDATE brand
       SET name       = NEW.taxonomyName,
           outletId   = NEW.outletId,
           extra      = NEW.taxonomyExtra,
           updated_at = now()
     WHERE brandId = NEW.taxonomyId;
  ELSIF TG_OP = 'DELETE' AND OLD.taxonomyType = 'brand' THEN
    DELETE FROM brand WHERE brandId = OLD.taxonomyId;
  END IF;

  PERFORM set_config('my.skip_brand_sync', 'off', true);
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_taxonomy_to_brand ON taxonomy;
CREATE TRIGGER trg_taxonomy_to_brand
AFTER INSERT OR UPDATE OR DELETE ON taxonomy
FOR EACH ROW EXECUTE FUNCTION sync_taxonomy_to_brand();

CREATE OR REPLACE FUNCTION sync_brand_to_taxonomy() RETURNS TRIGGER AS $$
BEGIN
  IF current_setting('my.skip_brand_sync', true) = 'on' THEN
    RETURN NULL;
  END IF;

  PERFORM set_config('my.skip_brand_sync', 'on', true);

  IF TG_OP = 'INSERT' THEN
    INSERT INTO taxonomy (taxonomyId, taxonomyName, taxonomyType, taxonomyExtra, outletId, companyId)
    VALUES (NEW.brandId, NEW.name, 'brand', NEW.extra, NEW.outletId, NEW.companyId)
    ON CONFLICT (taxonomyId) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' THEN
    UPDATE taxonomy
       SET taxonomyName  = NEW.name,
           taxonomyExtra = NEW.extra,
           outletId      = NEW.outletId
     WHERE taxonomyId = NEW.brandId AND taxonomyType = 'brand';
  ELSIF TG_OP = 'DELETE' THEN
    DELETE FROM taxonomy WHERE taxonomyId = OLD.brandId AND taxonomyType = 'brand';
  END IF;

  PERFORM set_config('my.skip_brand_sync', 'off', true);
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_brand_to_taxonomy ON brand;
CREATE TRIGGER trg_brand_to_taxonomy
AFTER INSERT OR UPDATE OR DELETE ON brand
FOR EACH ROW EXECUTE FUNCTION sync_brand_to_taxonomy();

-- ── 5. NOTAS para el corte futuro ─────────────────────────────────────────
-- 1. DROP TRIGGER trg_taxonomy_to_brand ON taxonomy;
-- 2. DROP TRIGGER trg_brand_to_taxonomy ON brand;
-- 3. DELETE FROM taxonomy WHERE taxonomyType = 'brand';
-- 4. Si querés migrar item.brandId → solo m2m:
--      ALTER TABLE item DROP COLUMN brandId;
--    (requiere que TODOS los consumers lean de item_brand). Hacer ESE paso
--    en un slice aparte cuando ya no haya legacy escribiendo a item.brandId.
