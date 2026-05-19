-- Migration: convertir item.itemImage de VARCHAR(10) a BOOLEAN
--
-- Antes: itemImage VARCHAR(10) DEFAULT 'false' — string libre ('true'/'false')
-- Después: itemImage BOOLEAN NOT NULL DEFAULT false
--
-- Phase 0 del refactor de Items (ver context/refactor-items.md).
-- Idempotente: si la columna ya es boolean, no hace nada.

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'item'
          AND column_name = 'itemimage'
          AND data_type = 'character varying'
    ) THEN
        -- Drop default antes de cambiar tipo (PG no permite default incompatible)
        ALTER TABLE item ALTER COLUMN itemImage DROP DEFAULT;

        -- Cambiar tipo con conversión explícita: 'true' → true, todo lo demás → false
        ALTER TABLE item
            ALTER COLUMN itemImage TYPE BOOLEAN
            USING (CASE WHEN itemImage = 'true' THEN true ELSE false END);

        -- Re-aplicar default + NOT NULL
        ALTER TABLE item ALTER COLUMN itemImage SET DEFAULT false;
        ALTER TABLE item ALTER COLUMN itemImage SET NOT NULL;

        RAISE NOTICE 'item.itemImage migrado a BOOLEAN';
    ELSE
        RAISE NOTICE 'item.itemImage ya es BOOLEAN, skip';
    END IF;
END
$$;
