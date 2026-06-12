-- 23_tax.sql
-- Slice 3 del refactor taxonomy → tablas dedicadas. PRIORIDAD ALTA por
-- afectar facturación.
--
-- Saca `tax` de `taxonomy WHERE taxonomyType='tax'` a su propia tabla.
-- Mantiene shape EXACTO: `name` guarda el mismo valor que taxonomyName
-- (en el dominio Punto-PY ese campo lleva el porcentaje del IVA — '10',
-- '5', '0', etc. — leído por getTaxValue()). NO separamos rate en
-- decimal todavía — eso viene en un slice futuro cuando todos los
-- consumers migren a leer de `tax`.
--
-- IMPORTANTE: los FKs deferred existen
--   - outlet.taxId → taxonomy(taxonomyId)
--   - item.taxId   → (sin FK explícito pero refs taxonomyId)
-- Como usamos el MISMO UUID en `tax` que en `taxonomy`, esos FKs siguen
-- siendo válidos (apuntan al mismo PK). NO se tocan en este slice.

-- ── 1. Tabla tax ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tax (
  taxId       UUID         PRIMARY KEY,
  companyId   UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletId    UUID         REFERENCES outlet(outletId) ON DELETE SET NULL,
  -- name = el valor del impuesto en formato compatible con getTaxValue().
  -- En Punto-PY es típicamente "10", "5", "0" — el porcentaje del IVA.
  -- Se mantiene el shape legacy para no romper facturación. Cuando todos
  -- los consumers migren se puede agregar una columna `rate DECIMAL` y
  -- normalizar.
  name        TEXT         NOT NULL,
  extra       TEXT,
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_tax_company ON tax(companyId);

-- ── 2. Backfill desde taxonomy ────────────────────────────────────────────
INSERT INTO tax (taxId, companyId, outletId, name, extra)
SELECT taxonomyId, companyId, outletId, taxonomyName, taxonomyExtra
FROM taxonomy
WHERE taxonomyType = 'tax'
  AND companyId IS NOT NULL
ON CONFLICT (taxId) DO NOTHING;

-- ── 3. Triggers bidireccionales ───────────────────────────────────────────
-- Flag de sesión separada: `my.skip_tax_sync`.

CREATE OR REPLACE FUNCTION sync_taxonomy_to_tax() RETURNS TRIGGER AS $$
BEGIN
  IF current_setting('my.skip_tax_sync', true) = 'on' THEN
    RETURN NULL;
  END IF;

  PERFORM set_config('my.skip_tax_sync', 'on', true);

  IF TG_OP = 'INSERT' AND NEW.taxonomyType = 'tax' THEN
    INSERT INTO tax (taxId, companyId, outletId, name, extra, created_at)
    VALUES (NEW.taxonomyId, NEW.companyId, NEW.outletId, NEW.taxonomyName, NEW.taxonomyExtra, now())
    ON CONFLICT (taxId) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' AND NEW.taxonomyType = 'tax' THEN
    UPDATE tax
       SET name       = NEW.taxonomyName,
           outletId   = NEW.outletId,
           extra      = NEW.taxonomyExtra,
           updated_at = now()
     WHERE taxId = NEW.taxonomyId;
  ELSIF TG_OP = 'DELETE' AND OLD.taxonomyType = 'tax' THEN
    DELETE FROM tax WHERE taxId = OLD.taxonomyId;
  END IF;

  PERFORM set_config('my.skip_tax_sync', 'off', true);
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_taxonomy_to_tax ON taxonomy;
CREATE TRIGGER trg_taxonomy_to_tax
AFTER INSERT OR UPDATE OR DELETE ON taxonomy
FOR EACH ROW EXECUTE FUNCTION sync_taxonomy_to_tax();

CREATE OR REPLACE FUNCTION sync_tax_to_taxonomy() RETURNS TRIGGER AS $$
BEGIN
  IF current_setting('my.skip_tax_sync', true) = 'on' THEN
    RETURN NULL;
  END IF;

  PERFORM set_config('my.skip_tax_sync', 'on', true);

  IF TG_OP = 'INSERT' THEN
    INSERT INTO taxonomy (taxonomyId, taxonomyName, taxonomyType, taxonomyExtra, outletId, companyId)
    VALUES (NEW.taxId, NEW.name, 'tax', NEW.extra, NEW.outletId, NEW.companyId)
    ON CONFLICT (taxonomyId) DO NOTHING;
  ELSIF TG_OP = 'UPDATE' THEN
    UPDATE taxonomy
       SET taxonomyName  = NEW.name,
           taxonomyExtra = NEW.extra,
           outletId      = NEW.outletId
     WHERE taxonomyId = NEW.taxId AND taxonomyType = 'tax';
  ELSIF TG_OP = 'DELETE' THEN
    DELETE FROM taxonomy WHERE taxonomyId = OLD.taxId AND taxonomyType = 'tax';
  END IF;

  PERFORM set_config('my.skip_tax_sync', 'off', true);
  RETURN NULL;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_tax_to_taxonomy ON tax;
CREATE TRIGGER trg_tax_to_taxonomy
AFTER INSERT OR UPDATE OR DELETE ON tax
FOR EACH ROW EXECUTE FUNCTION sync_tax_to_taxonomy();

-- ── 4. NOTAS para el corte futuro ─────────────────────────────────────────
-- Cuando facturación electrónica + reports + items lean de `tax`:
-- 1. DROP TRIGGER trg_taxonomy_to_tax ON taxonomy;
-- 2. DROP TRIGGER trg_tax_to_taxonomy ON tax;
-- 3. DELETE FROM taxonomy WHERE taxonomyType = 'tax';
-- 4. (Opcional, en slice futuro) Agregar columna `rate DECIMAL(5,2)` y
--    backfill desde `name::numeric` — normaliza el shape para queries
--    aritméticas en reports.
