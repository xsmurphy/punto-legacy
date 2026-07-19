-- 79_orders_core.sql
-- Módulo de Órdenes F0 (context/24-orders-module-plan.md).
--
-- Tres tablas nuevas — la orden es entidad PROPIA, separada de `transaction`.
-- `transaction` se crea SOLO al cobrar (SaleService), no para el ciclo
-- operativo de la orden. Corte limpio de los types legacy 11/12 (ver
-- context/15-espacios-module-plan.md D3): `api/v1/orders.php`/`OrderService.php`
-- legacy (pedidos online sobre transaction type=12) quedan intactos, es un
-- dominio distinto — no se tocan.
--
--   - order_station: estaciones de preparación (Cocina, Barra, ...) por
--     outlet. categoryids jsonb enruta ítems, mismo modelo que
--     printer_binding.categoryIds (comodín [] = atiende todo).
--   - pos_order: cabecera de la orden. Nombre `pos_order` porque `order` es
--     palabra reservada SQL. spacesessionid queda NULL hasta O3 (espacios).
--     (renombrada de `tablesessionid` en la mig 81 — rename mesas→espacios)
--   - pos_order_item: líneas de la orden, snapshot de nombre/precio,
--     estado individual de preparación, ruteada a una estación.
--
-- Todo lowercase sin comillas (mig 71/72 doc de convención).

CREATE TABLE IF NOT EXISTS order_station (
  stationid    UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid    UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid     UUID,
  name         VARCHAR(80)    NOT NULL,
  categoryids  JSONB          NOT NULL DEFAULT '[]',
  sort         INT            NOT NULL DEFAULT 0,
  status       SMALLINT       NOT NULL DEFAULT 1,
  created_at   TIMESTAMPTZ    NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_order_station_company_outlet ON order_station(companyid, outletid);

CREATE TABLE IF NOT EXISTS pos_order (
  orderid           UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid         UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid          UUID           NOT NULL,
  registerid        UUID,
  source            VARCHAR(16)    NOT NULL DEFAULT 'counter'
                       CHECK (source IN ('counter','table','ecommerce','schedule')),
  status            VARCHAR(16)    NOT NULL DEFAULT 'open'
                       CHECK (status IN ('open','sent','in_progress','ready','delivered','closed','cancelled')),
  ordernumber       INT,
  spacesessionid    UUID,
  customerid        UUID,
  userid            UUID,
  note              TEXT,
  channelref        VARCHAR(80),
  saletransactionid UUID,
  created_at        TIMESTAMPTZ    NOT NULL DEFAULT now(),
  sent_at           TIMESTAMPTZ,
  closed_at         TIMESTAMPTZ,
  data              JSONB          NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_pos_order_company_outlet_status ON pos_order(companyid, outletid, status);
CREATE INDEX IF NOT EXISTS idx_pos_order_company_created       ON pos_order(companyid, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_pos_order_saletransaction        ON pos_order(saletransactionid);

CREATE TABLE IF NOT EXISTS pos_order_item (
  orderitemid   UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  orderid       UUID           NOT NULL REFERENCES pos_order(orderid) ON DELETE CASCADE,
  companyid     UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  itemid        UUID,
  name          VARCHAR(160)   NOT NULL,
  qty           NUMERIC(15,3)  NOT NULL,
  price         NUMERIC(14,2),
  note          TEXT,
  stationid     UUID,
  status        VARCHAR(16)    NOT NULL DEFAULT 'pending'
                   CHECK (status IN ('pending','preparing','ready','delivered','cancelled')),
  course        SMALLINT       NOT NULL DEFAULT 1,
  created_at    TIMESTAMPTZ    NOT NULL DEFAULT now(),
  ready_at      TIMESTAMPTZ,
  delivered_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_pos_order_item_order            ON pos_order_item(orderid);
CREATE INDEX IF NOT EXISTS idx_pos_order_item_company_station   ON pos_order_item(companyid, stationid, status);
