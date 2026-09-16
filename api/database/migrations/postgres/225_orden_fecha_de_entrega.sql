-- 225_orden_fecha_de_entrega.sql
-- Fecha de entrega comprometida de una orden (context/79, F1).
--
-- ── Por qué una columna y no una clave del JSONB ────────────────────────────
--
-- Hasta acá `pos_order` solo tenía tiempos DEL SISTEMA: `created_at`,
-- `sent_at`, `closed_at` (mig 79). No había forma de decir "esto es para el
-- viernes", así que un pedido para dentro de una semana ensuciaba la cola de
-- cocina los siete días o simplemente no se cargaba.
--
-- Se filtra, se agrupa y se ordena por esta fecha en TRES pantallas (el lote
-- de producción por fecha, el KDS que no muestra el futuro, y el listado de
-- órdenes): con la fecha dentro de `data` cada una de esas pantallas paga un
-- scan de la tabla entera. Es el mismo criterio de la mig 220 (código de
-- barras) — lo que se filtra por igualdad o por rango es columna, no JSONB.
--
-- ── TIMESTAMPTZ y no DATE ───────────────────────────────────────────────────
--
-- D1 de context/79: el caso real es "para el viernes", pero la hora existe
-- (delivery puntual) y el día que se capture no queremos migrar el tipo. La UI
-- de hoy pide solo la fecha y guarda la medianoche del comercio; todas las
-- comparaciones son por DÍA (`scheduled_for::date`), que sale en hora del
-- tenant sin `AT TIME ZONE` porque `TenantClock::apply()` fija la zona de la
-- sesión de Postgres antes de cualquier query (mismo hallazgo de context/67).
--
-- NULL = "para ahora", que es lo que significa TODA orden existente. No hay
-- backfill: la columna nace vacía y el comportamiento no cambia para nadie.
--
-- ── El índice es PARCIAL ────────────────────────────────────────────────────
--
-- La enorme mayoría de las órdenes son para el momento y no tienen fecha: esas
-- filas no tienen por qué engordar el índice ni su mantenimiento. El orden de
-- las columnas acompaña a las tres consultas, que siempre acotan primero por
-- tenant y sucursal.

ALTER TABLE pos_order ADD COLUMN IF NOT EXISTS scheduled_for TIMESTAMPTZ;

CREATE INDEX IF NOT EXISTS idx_pos_order_scheduled_for
    ON pos_order (companyid, outletid, scheduled_for)
 WHERE scheduled_for IS NOT NULL;
