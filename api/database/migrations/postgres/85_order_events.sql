-- 85_order_events.sql
-- Historial de transiciones de órdenes (F-EVT-0, context/27-delivery-sla-plan.md
-- PARTE C). Independiente de F-SLA-0/F-D-0 — no depende de delivery ni de
-- las decisiones pendientes de la PARTE B, se ejecuta ya.
--
-- Por qué NO alcanzan las columnas de timestamp de pos_order/pos_order_item
-- (ready_at/sent_at/closed_at/etc): son un CACHE del último valor, no la
-- historia. Pierden las repeticiones (ready → in_progress → ready = re-trabajo,
-- justo lo que hay que detectar), quién marcó cada transición, y el nivel de
-- ítem — el cuello de botella es de ESTACIÓN (pos_order_item.stationid, mig
-- 79), no de orden. Por eso un solo log cubre los dos scopes ('order' e
-- 'item').
--
-- outletid denormalizado a propósito: las queries analíticas (rollups de
-- context/18, por outlet y por estación) escanean sin JOIN a pos_order.
-- stationid se SNAPSHOTEA (viene del ítem al momento del evento) — si mañana
-- se re-rutea la categoría a otra estación, la historia sigue diciendo quién
-- hizo qué esa noche.
--
-- Invariante (§C.4): el evento se escribe en la MISMA transacción que el
-- UPDATE/INSERT de status que lo origina — punto único de escritura
-- OrderCoreService::recordEvent(), privado. Si el evento y el estado
-- divergen, la historia miente.
--
-- Todo lowercase sin comillas (patrón migs 79/80/83 de este dominio).

CREATE TABLE IF NOT EXISTS pos_order_event (
  eventid      UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid    UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid     UUID           NOT NULL,
  orderid      UUID           NOT NULL REFERENCES pos_order(orderid) ON DELETE CASCADE,
  orderitemid  UUID,                   -- NULL = evento de orden
  stationid    UUID,                   -- snapshot de la estación del ítem
  scope        VARCHAR(8)     NOT NULL CHECK (scope IN ('order','item')),
  from_status  VARCHAR(16),            -- NULL en el evento de creación
  to_status    VARCHAR(16)    NOT NULL,
  actor_kind   VARCHAR(12)    NOT NULL CHECK (actor_kind IN ('user','device','system')),
  actor_id     UUID,                   -- userid o deviceid
  -- VARCHAR(20) igualando `device.module` (mig 63): con 12 un module futuro
  -- de nombre largo truncaría en silencio y ensuciaría el análisis por actor.
  actor_module VARCHAR(20),            -- pos | kds | display | panel | print
  created_at   TIMESTAMPTZ    NOT NULL DEFAULT now()
);

-- Timeline de una orden (detalle, OrderCoreService::find()).
CREATE INDEX IF NOT EXISTS idx_pos_order_event_order   ON pos_order_event(companyid, orderid, created_at);
-- Reportes por outlet (rollups día/mes, context/18).
CREATE INDEX IF NOT EXISTS idx_pos_order_event_outlet  ON pos_order_event(companyid, outletid, created_at DESC);
-- Análisis de cuellos de botella por estación (F-SLA-2, p75 sent→ready).
CREATE INDEX IF NOT EXISTS idx_pos_order_event_station ON pos_order_event(companyid, stationid, to_status, created_at);
