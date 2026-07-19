-- 82_space_sector_required.sql
-- Invariante: todo espacio SIEMPRE pertenece a un sector — no existe
-- "espacio sin sector" (decisión del owner, post rename mesas→espacios).
-- Los espacios con sectorid NULL quedaban invisibles en el editor de layout
-- (que agrupa por sector) — ese era el bug reportado.
--
-- Idempotente para AMBOS escenarios, mismo criterio que la mig 81:
--   1. Entorno con datos viejos (espacios con sectorid NULL, típico de
--      tenants con mesas creadas antes del sector-required): data-fix acá
--      abajo — asigna cada espacio huérfano al sector default de SU outlet
--      (lo crea si el outlet no tiene ninguno), después vuelve la columna
--      NOT NULL.
--   2. Fresh install: la mig 80 (editada in-place) ya crea `space.sectorid`
--      NOT NULL — sin filas huérfanas, el DO $$ de abajo es un no-op, y los
--      ALTER de NOT NULL/FK son no-op porque ya están en ese estado
--      (columna ya NOT NULL → el ALTER re-aplicado es válido en Postgres;
--      el DROP/ADD CONSTRAINT usa IF EXISTS + nombre fijo para no duplicar).

BEGIN;

-- 1. Data-fix: espacios huérfanos → sector default del outlet (creándolo si falta).
DO $$
DECLARE
    r RECORD;
    default_sector_id UUID;
BEGIN
    FOR r IN
        SELECT DISTINCT companyid, outletid FROM space WHERE sectorid IS NULL
    LOOP
        SELECT sectorid INTO default_sector_id
          FROM space_sector
         WHERE companyid = r.companyid AND outletid = r.outletid AND status = 1
         ORDER BY sort ASC, created_at ASC
         LIMIT 1;

        IF default_sector_id IS NULL THEN
            INSERT INTO space_sector (sectorid, companyid, outletid, name, sort)
            VALUES (gen_random_uuid(), r.companyid, r.outletid, 'Salón', 0)
            RETURNING sectorid INTO default_sector_id;
        END IF;

        UPDATE space
           SET sectorid = default_sector_id
         WHERE companyid = r.companyid AND outletid = r.outletid AND sectorid IS NULL;

        default_sector_id := NULL;
    END LOOP;
END $$;

-- 2. NOT NULL — ya no puede fallar, el paso 1 dejó cero filas con sectorid NULL.
ALTER TABLE space ALTER COLUMN sectorid SET NOT NULL;

-- 3. FK: la definición original era ON DELETE SET NULL (incompatible con
--    NOT NULL). El nombre físico del constraint depende de si la tabla se
--    creó como `dining_table` (entornos viejos, mig 80 pre-rename) o ya
--    como `space` (fresh installs post rename) — se intentan ambos nombres
--    posibles con IF EXISTS antes de crear el definitivo.
ALTER TABLE space DROP CONSTRAINT IF EXISTS dining_table_sectorid_fkey;
ALTER TABLE space DROP CONSTRAINT IF EXISTS space_sectorid_fkey;
ALTER TABLE space ADD CONSTRAINT space_sectorid_fkey
    FOREIGN KEY (sectorid) REFERENCES space_sector(sectorid) ON DELETE RESTRICT;

COMMIT;
