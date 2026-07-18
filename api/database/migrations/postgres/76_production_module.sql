-- 76_production_module.sql
-- Módulo de Producción F1 (context/23-production-module-plan.md).
--
-- Dos tablas nuevas:
--   - production_order: cabecera de una orden de fabricación. Dos flujos:
--     draft→in_progress→completed (con seguimiento) o create+complete
--     atómico ("producir ahora"). Snapshot de receta + costeo real quedan
--     en la fila una vez completada (recipesnapshot/ingredientcost/unitcogs).
--   - waste_event: merma REAL auditable (separada del % de merma
--     PLANIFICADO en item.itemWaste, que sigue afectando solo el cálculo de
--     necesidad vía getNeedWithWaste). source='production' cuando viene de
--     completar una orden con unidades falladas; source='manual' para
--     registro directo de merma (rotura, vencimiento, etc).
--
-- Todo lowercase sin comillas (mig 71/72 doc de convención).

CREATE TABLE IF NOT EXISTS production_order (
  orderid           UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid         UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid          UUID           NOT NULL,
  locationid         UUID,
  outputlocationid   UUID,
  itemid            UUID           NOT NULL REFERENCES item(itemId) ON DELETE RESTRICT,
  qtyplanned        NUMERIC(15,3)  NOT NULL,
  qtyproduced       NUMERIC(15,3),
  qtywaste          NUMERIC(15,3)  NOT NULL DEFAULT 0,
  status            VARCHAR(16)    NOT NULL DEFAULT 'draft'
                       CHECK (status IN ('draft','in_progress','completed','cancelled')),
  recipesnapshot    JSONB,
  ingredientcost    NUMERIC(14,2),
  unitcogs          NUMERIC(14,4),
  note              TEXT,
  userid            UUID,
  created_at        TIMESTAMPTZ    NOT NULL DEFAULT now(),
  started_at        TIMESTAMPTZ,
  completed_at      TIMESTAMPTZ,
  data              JSONB          NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_production_order_company_status  ON production_order(companyid, status);
CREATE INDEX IF NOT EXISTS idx_production_order_company_created ON production_order(companyid, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_production_order_item            ON production_order(itemid);

CREATE TABLE IF NOT EXISTS waste_event (
  wasteid       UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid     UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid      UUID,
  locationid    UUID,
  itemid        UUID           NOT NULL REFERENCES item(itemId) ON DELETE RESTRICT,
  qty           NUMERIC(15,3)  NOT NULL CHECK (qty > 0),
  reasonid      UUID,
  source        VARCHAR(16)    NOT NULL DEFAULT 'manual'
                   CHECK (source IN ('production','manual')),
  orderid       UUID           REFERENCES production_order(orderid) ON DELETE SET NULL,
  cost          NUMERIC(14,2),
  note          TEXT,
  userid        UUID,
  created_at    TIMESTAMPTZ    NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_waste_event_company_created ON waste_event(companyid, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_waste_event_reason           ON waste_event(reasonid);
CREATE INDEX IF NOT EXISTS idx_waste_event_order             ON waste_event(orderid);
