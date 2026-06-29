-- 33_expenses_name_nullable.sql
--
-- Contexto: los movimientos de caja (extracciones/ingresos desde el POS) se
-- insertan en `expenses` sin una categoría de taxonomy (expensesNameId).
-- En MySQL el legacy pasaba dec('NX') = 'NX' (string) y las FKs no se
-- enforzaban. En Postgres la constraint es real — fallaría con FK violation.
--
-- Decisión: expensesNameId es opcional para movimientos de caja. Solo aplica
-- a gastos/compras ingresadas desde el panel donde el usuario elige una
-- categoría. Los movimientos de caja del POS quedan NULL.
--
-- Idempotente.

DO $$
DECLARE
    v_constraint TEXT;
BEGIN
    -- Drop FK con nombre dinámico (sea cual sea: tabla_col_fkey, fk_*, etc.)
    SELECT tc.constraint_name INTO v_constraint
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu
      ON tc.constraint_name = kcu.constraint_name
     AND tc.table_schema    = kcu.table_schema
    WHERE tc.table_name      = 'expenses'
      AND tc.constraint_type = 'FOREIGN KEY'
      AND kcu.column_name    = 'expensesnameid'
    LIMIT 1;

    IF v_constraint IS NOT NULL THEN
        EXECUTE 'ALTER TABLE expenses DROP CONSTRAINT ' || quote_ident(v_constraint);
        RAISE NOTICE '[33] Dropped FK constraint % from expenses.expensesnameid', v_constraint;
    END IF;

    -- Nullable (idempotente: DROP NOT NULL es no-op si ya es nullable)
    -- Postgres fold-to-lowercase: la columna real es `expensesnameid` (sin quotes).
    ALTER TABLE expenses ALTER COLUMN expensesnameid DROP NOT NULL;
    RAISE NOTICE '[33] expenses.expensesnameid is now nullable';
END $$;
