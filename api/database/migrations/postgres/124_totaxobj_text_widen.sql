-- 124_totaxobj_text_widen.sql
-- `toTaxObj.toTaxObjText` de VARCHAR(255) a TEXT.
--
-- Deuda registrada en SaleService::persistRelations (F2a, context/38): ahí se
-- guarda el desglose de impuestos por tasa como JSON — una lista de
-- {taxId, rate, kind, base, amount} por cada (tasa, tipo) de la venta. Con
-- ~6 tasas distintas el json_encode pasa los 255 chars, PG aborta la
-- transacción por truncación (SQLSTATE 22001) y la VENTA ENTERA falla con
-- SaleAbortedException. No es una degradación silenciosa: es el cobro caído.
--
-- Hoy no explota porque Paraguay usa tres tasas (10/5/exento) y el JSON entra
-- holgado. Pero el plan de impuestos multi-país (context/38) existe justamente
-- para soportar tasas arbitrarias por país, así que el techo es una bomba de
-- tiempo con fecha puesta en el primer tenant de otro país.
--
-- El ancho es lo único que cambia: TEXT y VARCHAR(n) comparten representación
-- en PG (varlena), así que el ALTER no reescribe la tabla ni toca los datos —
-- no hay riesgo de pérdida y los valores existentes quedan intactos.
--
-- Convenciones de deploy (precedente migs 74/77 que tiraron todos los deploys
-- en prod): PROHIBIDO el operador `?`/`?|`/`?&` de jsonb — no aplica acá.
-- Idempotente: re-correr un ALTER … TYPE text sobre una columna que ya es text
-- es un no-op para PG, pero igual se guarda bajo un DO que lo verifica para no
-- pagar un lock innecesario en un re-run.

BEGIN;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
          FROM information_schema.columns
         WHERE table_name  = 'totaxobj'
           AND column_name = 'totaxobjtext'
           AND data_type   = 'character varying'
    ) THEN
        ALTER TABLE toTaxObj ALTER COLUMN toTaxObjText TYPE text;
    END IF;
END $$;

COMMIT;
