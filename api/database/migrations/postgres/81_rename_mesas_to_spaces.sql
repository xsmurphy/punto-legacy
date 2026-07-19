-- 81_rename_mesas_to_spaces.sql
-- Rename del módulo "Mesas" → "Espacios" (context/15-espacios-module-plan.md).
-- El concepto pasa a ser genérico: un ESPACIO físico con sesión abierta al
-- que se le asocian órdenes hasta cobrar — mesas (gastronomía), sillas de
-- atención (peluquerías), habitaciones (hostales/hoteles).
--
-- Esta migración es idempotente para DOS escenarios:
--   1. Entorno que ya corrió la mig 80 con los nombres viejos
--      (table_sector/dining_table/table_session) → todos los ALTER de acá
--      corren y dejan el schema en los nombres nuevos.
--   2. Fresh install: las migs 79/80 en este mismo repo ya fueron editadas
--      para crear directamente space_sector/space/space_session/
--      pos_order.spacesessionid → todos los IF EXISTS de acá no encuentran
--      nada que renombrar y la migración es un no-op.
--
-- Los `shape` (square/round/rect/bar/decor_wall/decor_plant) NO se tocan —
-- son formas geométricas, no tienen relación con "mesa" como palabra.

BEGIN;

-- ---------------------------------------------------------------------
-- 1. Tablas
-- ---------------------------------------------------------------------

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'table_sector')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'space_sector') THEN
        ALTER TABLE table_sector RENAME TO space_sector;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'dining_table')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'space') THEN
        ALTER TABLE dining_table RENAME TO space;
    END IF;

    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'table_session')
       AND NOT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = 'space_session') THEN
        ALTER TABLE table_session RENAME TO space_session;
    END IF;
END $$;

-- ---------------------------------------------------------------------
-- 2. Índices con nombre (Postgres NO los renombra solo al renombrar la
--    tabla — hay que hacerlo explícito). Índices implícitos de PK no
--    necesitan rename (siguen siendo válidos con el nombre viejo).
-- ---------------------------------------------------------------------

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_table_sector_company_outlet')
       AND NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_space_sector_company_outlet') THEN
        ALTER INDEX idx_table_sector_company_outlet RENAME TO idx_space_sector_company_outlet;
    END IF;

    IF EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_dining_table_company_outlet')
       AND NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_space_company_outlet') THEN
        ALTER INDEX idx_dining_table_company_outlet RENAME TO idx_space_company_outlet;
    END IF;

    IF EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_dining_table_sector')
       AND NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_space_sector') THEN
        ALTER INDEX idx_dining_table_sector RENAME TO idx_space_sector;
    END IF;

    IF EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_table_session_active_per_table')
       AND NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'uq_space_session_active_per_space') THEN
        ALTER INDEX uq_table_session_active_per_table RENAME TO uq_space_session_active_per_space;
    END IF;

    IF EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_table_session_company_outlet_status')
       AND NOT EXISTS (SELECT 1 FROM pg_indexes WHERE indexname = 'idx_space_session_company_outlet_status') THEN
        ALTER INDEX idx_table_session_company_outlet_status RENAME TO idx_space_session_company_outlet_status;
    END IF;
END $$;

-- ---------------------------------------------------------------------
-- 3. pos_order.tablesessionid → spacesessionid (mismo patrón IF EXISTS
--    que la mig 71 — tolera que la columna ya se llame como corresponde
--    en un fresh install con la mig 79 editada).
-- ---------------------------------------------------------------------

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'pos_order' AND column_name = 'tablesessionid'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'pos_order' AND column_name = 'spacesessionid'
    ) THEN
        ALTER TABLE pos_order RENAME COLUMN tablesessionid TO spacesessionid;
    END IF;
END $$;

COMMIT;
