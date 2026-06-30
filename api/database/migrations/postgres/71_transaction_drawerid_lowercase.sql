BEGIN;

-- Fix de mig 70: la columna se creó ENTRE COMILLAS ("drawerId") → nombre físico
-- case-sensitive `drawerId`. Pero ADOdb AutoExecute referencia la columna sin
-- comillas (key 'drawerId' del records array) y Postgres pliega los identificadores
-- sin comillas a minúscula → el INSERT busca `drawerid` → "column does not exist".
-- Rompía POST /v1/sales y /v1/credit-payments. El resto del schema usa nombres
-- físicos en minúscula (transactionDate/registerId andan porque su columna real es
-- lowercase). Renombramos para alinear. Idempotente: solo renombra si la columna
-- quedó en mixed-case y todavía no existe la lowercase (fresh installs ya la crean
-- lowercase en la mig 70 corregida → este bloque se saltea).
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'transaction' AND column_name = 'drawerId'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'transaction' AND column_name = 'drawerid'
    ) THEN
        ALTER TABLE transaction RENAME COLUMN "drawerId" TO drawerid;
    END IF;
END $$;

COMMIT;
