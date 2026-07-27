-- 89_order_event_reason.sql
-- Motivo de una transición de orden.
--
-- Regla del owner (2026-07-19): una orden NUNCA se elimina — se cancela, y
-- toda cancelación DEBE tener un motivo. El motivo vive en el log de eventos
-- (`pos_order_event`, mig 85), que ya es la fuente de verdad de la historia:
-- ahí queda junto al cuándo, el desde/hacia y el actor, sin denormalizar un
-- `cancelreason` en `pos_order` que quedaría desincronizado si una orden se
-- cancelara más de una vez o si otra transición también necesitara motivo.
--
-- Nullable a propósito: solo `to_status='cancelled'` lo exige (enforcement en
-- OrderCoreService, no un CHECK — las filas del backfill de la mig 86 son
-- históricas y no tienen motivo que registrar).
ALTER TABLE pos_order_event
  ADD COLUMN IF NOT EXISTS reason TEXT;
