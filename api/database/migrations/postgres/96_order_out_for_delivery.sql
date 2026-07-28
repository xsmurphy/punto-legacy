-- 96_order_out_for_delivery.sql
-- Estado `out_for_delivery` ("En camino"), primera mitad de F-D-1
-- (context/27-delivery-sla-plan.md §B.2). `courierid`, la vista de despacho
-- por repartidor y el tracking quedan FUERA — dependen de la decisión
-- pendiente del owner (§B.6.2) sobre si el repartidor tiene app propia.
-- Idempotente — corre en cada boot (patrón migs 85/94).
--
-- El CHECK de pos_order.status viene de la mig 79 como constraint inline sin
-- nombre explícito en el CREATE TABLE, así que Postgres lo bautizó con el
-- nombre default `tabla_columna_check`. Lo ubicamos por columna afectada
-- (vía pg_get_constraintdef, no por nombre asumido a ciegas) y lo
-- reemplazamos solo si todavía no incluye 'out_for_delivery' — así el DO $$
-- no pisa nada en un boot posterior donde ya corrió.
--
-- Guards de la transición (solo desde 'ready', solo si fulfillment='delivery')
-- viven en código: OrderCoreService::ORDER_TRANSITIONS y updateStatus().
-- Todo lowercase sin comillas (patrón migs 79/94 de este módulo).

DO $$
DECLARE
  v_conname text;
  v_condef  text;
BEGIN
  SELECT conname, pg_get_constraintdef(oid) INTO v_conname, v_condef
  FROM pg_constraint
  WHERE conrelid = 'pos_order'::regclass
    AND contype = 'c'
    AND pg_get_constraintdef(oid) LIKE '%(status)%'
  LIMIT 1;

  IF v_conname IS NOT NULL AND v_condef NOT LIKE '%out_for_delivery%' THEN
    EXECUTE format('ALTER TABLE pos_order DROP CONSTRAINT %I', v_conname);
    ALTER TABLE pos_order
      ADD CONSTRAINT pos_order_status_check
      CHECK (status IN ('open','sent','in_progress','ready','out_for_delivery','delivered','closed','cancelled'));
  ELSIF v_conname IS NULL THEN
    ALTER TABLE pos_order
      ADD CONSTRAINT pos_order_status_check
      CHECK (status IN ('open','sent','in_progress','ready','out_for_delivery','delivered','closed','cancelled'));
  END IF;
END $$;
