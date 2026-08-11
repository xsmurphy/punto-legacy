-- 131_stock_cogs_rebuild.sql
-- Reconstruye el costeo del historial de stock: `stockOnHandCOGS` de todas las
-- filas y `stockCOGS` de los EGRESOS.
--
-- Reemplaza a la mig 130 en el recálculo del saldo (lo rehace igual, es
-- idempotente): un solo recorrido deja saldo y costo consistentes.
--
-- POR QUÉ el costo quedó destruido — dos causas encadenadas:
--
--   1. El egreso reseteaba el promedio a 0 cuando el saldo quedaba <= 0
--      (`$newTotalCOGS = ($newOnHand <= 0) ? 0 : $oldACOGS`). Como el saldo
--      venía mal calculado y casi todos los ítems figuraban en negativo, el
--      costo promedio se borró en cascada: 335 de 412 filas quedaron en 0.
--   2. En el ingreso, `divider()` (Math::divide) devuelve 0 si CUALQUIERA de
--      los dos operandos es <= 0. Una compra sobre saldo negativo daba
--      numerador negativo y el promedio terminaba en 0 en vez de recalcularse.
--
-- SE PUEDE reconstruir porque el costo de los INGRESOS está completo: las 47
-- filas de ingreso tienen `stockCOGS` cargado (verificado). Ese es el dato de
-- entrada — lo que se pagó — y no se toca. Todo lo demás se deriva replayeando
-- el historial en orden.
--
-- Reglas (las MISMAS que `Inventory::manageStock()` y `rebuildLedger()`):
--   - ingreso: promedio = (promedio*base + costo*cantidad) / (base + cantidad),
--     con base = max(saldo previo, 0) — un saldo negativo no se compró a
--     ningún precio, así que no aporta base.
--   - egreso: sale al promedio vigente y NO lo altera.
--
-- `stockCOGS` de los ingresos queda intacto. Si un ingreso vino con costo 0
-- (hay 1), su promedio resultante baja: es fiel al dato cargado, no un error
-- de esta reconstrucción.

BEGIN;

DO $$
DECLARE
  r           record;
  v_item      uuid := NULL;
  v_outlet    uuid := NULL;
  v_qty       numeric := 0;
  v_avg       numeric := 0;
  v_base      numeric;
  v_total     numeric;
  v_cost      numeric;
  v_touched   int := 0;
BEGIN
  FOR r IN
    SELECT stockid, itemid, outletid, stockcount, stockcogs, stockonhand, stockonhandcogs
      FROM stock
     ORDER BY itemid, outletid, stockdate, stockid
  LOOP
    -- Nuevo par (ítem, sucursal): el acumulado arranca de cero.
    IF v_item IS DISTINCT FROM r.itemid OR v_outlet IS DISTINCT FROM r.outletid THEN
      v_item   := r.itemid;
      v_outlet := r.outletid;
      v_qty    := 0;
      v_avg    := 0;
    END IF;

    v_cost := COALESCE(r.stockcogs, 0);

    IF COALESCE(r.stockcount, 0) > 0 THEN
      v_base  := GREATEST(v_qty, 0);
      v_total := v_base + r.stockcount;
      IF v_total > 0 THEN
        v_avg := ((v_avg * v_base) + (v_cost * r.stockcount)) / v_total;
      ELSE
        v_avg := v_cost;
      END IF;
    ELSE
      -- Egreso: se cuesta al promedio vigente.
      v_cost := v_avg;
    END IF;

    v_qty := v_qty + COALESCE(r.stockcount, 0);

    IF r.stockonhand IS DISTINCT FROM v_qty
       OR r.stockonhandcogs IS DISTINCT FROM v_avg
       OR (COALESCE(r.stockcount, 0) <= 0 AND r.stockcogs IS DISTINCT FROM v_cost) THEN
      UPDATE stock
         SET stockonhand     = v_qty,
             stockonhandcogs = v_avg,
             stockcogs       = v_cost
       WHERE stockid = r.stockid;
      v_touched := v_touched + 1;
    END IF;
  END LOOP;

  RAISE NOTICE 'mig 131: % filas de stock recalculadas', v_touched;
END $$;

COMMIT;
