-- 119_transaction_units_decimal.sql
-- `transaction.transactionUnitsSold` era INT: las ventas con cantidad decimal
-- no persistían.
--
-- El schema original ya tenía el resto de las columnas de cantidad en
-- DECIMAL(15,3) — `itemSold.itemSoldUnits` incluso con el comentario
-- "was float; DECIMAL avoids floating-point issues" — pero esta quedó en INT.
--
-- SaleService escribe `transactionUnitsSold` con la SUMA de las cantidades de
-- las líneas (countUnitSold, floats). Vender 1,5 kg mandaba `1.5` a una columna
-- INT y PG lo rechazaba con "invalid input syntax for type integer". A partir
-- de ahí la transacción quedaba envenenada: todo statement siguiente devolvía
-- 25P02 y el COMMIT se degradaba a ROLLBACK silencioso, así que la venta
-- terminaba en SaleAbortedException "no persistió tras commit" — el síntoma que
-- reportó el tester (2026-08-04), y que solo aparecía con decimales.
--
-- PurchasesService:429 escribe la misma columna, así que las compras con
-- cantidad decimal tenían el mismo bug.
--
-- Compatible aguas abajo: los rollups la suman hacia `qty NUMERIC(18,4)` y
-- todos los lectores PHP castean con (float) o usan SUM(). Ningún caller asume
-- entero.
--
-- ⚠️ ALTER COLUMN ... TYPE reescribe la tabla y toma ACCESS EXCLUSIVE. En
-- `transaction` eso bloquea escrituras mientras dura. Hoy el volumen es chico;
-- si crece, hacerlo en ventana de baja actividad.

BEGIN;

DO $$
BEGIN
  -- Guard por tipo actual: evita una reescritura innecesaria de la tabla si la
  -- migración se re-corre.
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name  = 'transaction'
       AND column_name = 'transactionunitssold'
       AND data_type   IN ('integer', 'smallint', 'bigint')
  ) THEN
    ALTER TABLE transaction
      ALTER COLUMN transactionUnitsSold TYPE DECIMAL(15,3);
  END IF;
END $$;

COMMENT ON COLUMN transaction.transactionUnitsSold IS
  'Suma de las unidades vendidas de la transacción. DECIMAL(15,3) como itemSold.itemSoldUnits — hay ítems fraccionables (kg, litros).';

COMMIT;
