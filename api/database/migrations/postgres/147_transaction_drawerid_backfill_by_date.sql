-- 147_transaction_drawerid_backfill_by_date.sql
-- Recorrige transaction.drawerid mal atribuido por resolveOpenDrawerId().
--
-- Hasta hoy, transaction.drawerid (mig 70/71) se resolvía preguntando "¿qué
-- caja está abierta AHORA?" en el momento del INSERT (DrawerService::
-- resolveOpenDrawerId, api/lib/services/DrawerService.php). Para una venta
-- OFFLINE el INSERT ocurre recién al sincronizar, horas después de cobrada:
-- si la caja rotó de turno entre la venta real y el sync, la transacción
-- quedaba colgada del turno abierto AL SINCRONIZAR, no del que la cobró. El
-- arqueo del turno siguiente mostraba plata ajena; el turno real ya había
-- cerrado con su total corto. Reemplazo (mismo commit): DrawerService::
-- resolveDrawerIdForDate(), que resuelve por CONTENCIÓN de transactionDate
-- dentro del rango [drawerOpenDate, drawerCloseDate] de la MISMA caja —
-- determinista porque uidx_drawer_register_open garantiza que nunca hay dos
-- turnos abiertos a la vez en una caja, así que sus rangos no se solapan.
--
-- Este backfill aplica esa misma regla retroactivamente:
--
--   1) Filas con drawerid NO NULL cuyo drawer actual NO contiene su propia
--      transactionDate, con EXACTAMENTE un drawer candidato de la MISMA
--      caja+company que sí la contiene → reasignadas a ese candidato.
--   2) Mismas filas "wrong" del punto 1 pero SIN candidato único (cero
--      candidatos, o más de uno — no debería pasar dado el índice único de
--      arriba, pero ese índice solo cubre el turno ABIERTO, no descarta
--      rangos superpuestos entre turnos CERRADOS si alguna vez se corrigió
--      una fecha a mano) → drawerid = NULL.
--
-- Por qué NULL y no "dejar como está" en el punto 2: un drawerid que hoy NO
-- contiene la fecha real de la venta es la manifestación exacta del bug que
-- este backfill corrige. Dejarlo tal cual perpetúa el arqueo mal atribuido.
-- NULL es estrictamente más seguro — mig 70 ya define el fallback: el
-- resumen de caja recupera por fecha cuando drawerid es NULL
-- (getPaymentBreakdown/getSaleStats/etc., ver context/modules/14-caja.md
-- regla 2). Un drawerid incorrecto NO es recuperable: hace match exacto y
-- gana sobre ese mismo fallback. "Fallar es más seguro que acertar mal".
--
-- Filas fuera de alcance (no tocadas): drawerid ya NULL (nada que corregir,
-- ya usan el fallback), y transactionDate NULL (la columna es NOT NULL en
-- el schema — defensivo, no debería haber filas así, pero si las hay no hay
-- fecha con la que decidir nada).
--
-- Nunca aborta el deploy: todo el cuerpo va dentro de un bloque
-- EXCEPTION WHEN OTHERS que degrada a RAISE WARNING (precedente: migs 74/77/
-- 122 tumbaron deploys enteros con el health respondiendo 200 sobre la
-- imagen vieja). Idempotente y re-corrible: cada corrida recalcula el
-- estado actual de la tabla, así que una fila ya corregida simplemente deja
-- de aparecer en el CTE "wrong" de la corrida siguiente.

BEGIN;

DO $$
DECLARE
  fixed_count  bigint := 0;
  nulled_count bigint := 0;
BEGIN
  -- Paso 1 — reasignar al único candidato correcto.
  WITH wrong AS (
    SELECT t.transactionid, t.registerid, t.companyid, t.transactiondate
      FROM transaction t
      JOIN drawer d ON d.drawerid = t.drawerid
     WHERE t.drawerid IS NOT NULL
       AND t.transactiondate IS NOT NULL
       AND NOT (
             d.draweropendate <= t.transactiondate
         AND (d.drawerclosedate IS NULL
              OR d.drawerclosedate < '2000-01-01 00:00:00'
              OR d.drawerclosedate >= t.transactiondate)
       )
  ),
  candidates AS (
    SELECT w.transactionid,
           c.drawerid AS candidate_drawerid,
           count(*) OVER (PARTITION BY w.transactionid) AS n_candidates
      FROM wrong w
      JOIN drawer c
        ON c.registerid = w.registerid
       AND c.companyid  = w.companyid
       AND c.draweropendate <= w.transactiondate
       AND (c.drawerclosedate IS NULL
            OR c.drawerclosedate < '2000-01-01 00:00:00'
            OR c.drawerclosedate >= w.transactiondate)
  ),
  unique_candidates AS (
    SELECT transactionid, candidate_drawerid
      FROM candidates
     WHERE n_candidates = 1
  )
  UPDATE transaction t
     SET drawerid = uc.candidate_drawerid
    FROM unique_candidates uc
   WHERE t.transactionid = uc.transactionid;

  GET DIAGNOSTICS fixed_count = ROW_COUNT;

  -- Paso 2 — el resto de las filas "wrong" (0 o >1 candidatos): NULL.
  -- Recalcula "wrong" desde cero: las filas ya corregidas en el paso 1 ya
  -- no aparecen (su drawerid actual ahora sí contiene la fecha).
  WITH wrong AS (
    SELECT t.transactionid, t.registerid, t.companyid, t.transactiondate
      FROM transaction t
      JOIN drawer d ON d.drawerid = t.drawerid
     WHERE t.drawerid IS NOT NULL
       AND t.transactiondate IS NOT NULL
       AND NOT (
             d.draweropendate <= t.transactiondate
         AND (d.drawerclosedate IS NULL
              OR d.drawerclosedate < '2000-01-01 00:00:00'
              OR d.drawerclosedate >= t.transactiondate)
       )
  ),
  candidate_counts AS (
    SELECT w.transactionid,
           count(c.drawerid) AS n_candidates
      FROM wrong w
      LEFT JOIN drawer c
        ON c.registerid = w.registerid
       AND c.companyid  = w.companyid
       AND c.draweropendate <= w.transactiondate
       AND (c.drawerclosedate IS NULL
            OR c.drawerclosedate < '2000-01-01 00:00:00'
            OR c.drawerclosedate >= w.transactiondate)
     GROUP BY w.transactionid
    HAVING count(c.drawerid) != 1
  )
  UPDATE transaction t
     SET drawerid = NULL
    FROM candidate_counts cc
   WHERE t.transactionid = cc.transactionid;

  GET DIAGNOSTICS nulled_count = ROW_COUNT;

  RAISE NOTICE 'mig 144: % filas de transaction reasignadas al drawer correcto por fecha, % filas puestas en drawerid=NULL (sin candidato único, recuperables por el fallback de fecha del resumen).',
    fixed_count, nulled_count;
EXCEPTION WHEN OTHERS THEN
  RAISE WARNING 'mig 144: backfill de transaction.drawerid abortado por error, sin tocar filas. Detalle: %', SQLERRM;
END $$;

COMMIT;
