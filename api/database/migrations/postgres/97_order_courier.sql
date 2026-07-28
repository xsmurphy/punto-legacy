-- 97_order_courier.sql
-- Repartidor asignado a la orden (F-D-1, context/27-delivery-sla-plan.md
-- §B.6.2). Idempotente — corre en cada boot (patrón migs 94/96).
--
-- El repartidor es una PERSONA (staff), no un dispositivo pareado —
-- `courierid` es referencia LÓGICA a `contact`, sin FK dura, mismo criterio
-- que `customerid` en `pos_order` (mig 79). Hoy (F-D-1) la caja asigna el
-- repartidor; cuando exista su app propia (§B.6.2, resuelto 2026-07-28) va a
-- usar la misma columna.
--
-- Índice parcial (`WHERE courierid IS NOT NULL`) porque la mayoría de las
-- órdenes no tiene repartidor asignado — sirve para la futura vista de
-- despacho que agrupa por courier.
--
-- Todo lowercase sin comillas (patrón migs 79+ de este módulo).

ALTER TABLE pos_order ADD COLUMN IF NOT EXISTS courierid UUID;

CREATE INDEX IF NOT EXISTS idx_pos_order_company_courier
  ON pos_order(companyid, courierid)
  WHERE courierid IS NOT NULL;
