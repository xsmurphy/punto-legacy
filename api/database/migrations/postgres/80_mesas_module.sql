-- 80_mesas_module.sql
-- Módulo de Mesas F0+F1 (context/15-mesas-module-plan.md, sobre el core de
-- Órdenes O0/O1 — context/24-orders-module-plan.md).
--
-- Tres tablas nuevas — capa espacial encima de `pos_order`. `pos_order.
-- tablesessionid` (mig 79, ya nullable) es el link: una `table_session`
-- agrupa una o más `pos_order` de la misma mesa/ronda de servicio.
--
--   - table_sector: zonas del salón (Terraza, Salón, Barra) por outlet.
--   - dining_table: la mesa como entidad. `table` es palabra reservada SQL,
--     de ahí el nombre. posx/posy/width/height NULL = sin layout custom →
--     el front la ubica en la grilla numerada default (F1, editor de layout).
--   - table_session: instancia de ocupación (abrir mesa → sesión activa).
--     Índice único parcial garantiza una sola sesión open|bill_requested por
--     mesa — respalda TableSessionService.open() contra condiciones de
--     carrera sin lock explícito.
--
-- Reservas / table_assignment / table_settlement (context/15 §2.4-2.6) quedan
-- para F4/F3 — fuera de alcance de F0+F1. La cuenta/cobro de la sesión (F2)
-- tampoco se implementa acá: TableSessionService.close() solo cierra el
-- registro, no factura.
--
-- Todo lowercase sin comillas (patrón migs 72/76/79).

CREATE TABLE IF NOT EXISTS table_sector (
  sectorid    UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid   UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid    UUID           NOT NULL,
  name        VARCHAR(80)    NOT NULL,
  sort        INT            NOT NULL DEFAULT 0,
  status      SMALLINT       NOT NULL DEFAULT 1,
  created_at  TIMESTAMPTZ    NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_table_sector_company_outlet ON table_sector(companyid, outletid);

CREATE TABLE IF NOT EXISTS dining_table (
  tableid     UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid   UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid    UUID           NOT NULL,
  sectorid    UUID           REFERENCES table_sector(sectorid) ON DELETE SET NULL,
  name        VARCHAR(40)    NOT NULL,
  seats       SMALLINT       NOT NULL DEFAULT 4,
  shape       VARCHAR(16)    NOT NULL DEFAULT 'square'
                 CHECK (shape IN ('square','round','rect','bar','decor_wall','decor_plant')),
  posx        NUMERIC(8,2),
  posy        NUMERIC(8,2),
  width       NUMERIC(8,2),
  height      NUMERIC(8,2),
  rotation    SMALLINT       NOT NULL DEFAULT 0,
  status      SMALLINT       NOT NULL DEFAULT 1,
  sort        INT            NOT NULL DEFAULT 0,
  created_at  TIMESTAMPTZ    NOT NULL DEFAULT now(),
  data        JSONB          NOT NULL DEFAULT '{}'
);

CREATE INDEX IF NOT EXISTS idx_dining_table_company_outlet ON dining_table(companyid, outletid);
CREATE INDEX IF NOT EXISTS idx_dining_table_sector          ON dining_table(sectorid);

CREATE TABLE IF NOT EXISTS table_session (
  sessionid          UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid          UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid           UUID           NOT NULL,
  tableid            UUID           NOT NULL REFERENCES dining_table(tableid) ON DELETE CASCADE,
  status             VARCHAR(16)    NOT NULL DEFAULT 'open'
                        CHECK (status IN ('open','bill_requested','closed','cancelled')),
  guests             SMALLINT,
  waiterid           UUID,
  opened_at          TIMESTAMPTZ    NOT NULL DEFAULT now(),
  closed_at          TIMESTAMPTZ,
  saletransactionid  UUID,
  note               TEXT,
  data               JSONB          NOT NULL DEFAULT '{}'
);

-- Una sola sesión activa (open|bill_requested) por mesa — el índice único
-- parcial es la fuente de verdad de la invariante, no un check applicativo;
-- TableSessionService.open() confía en la violación de constraint para
-- devolver un error claro ante la carrera de dos aperturas concurrentes.
CREATE UNIQUE INDEX IF NOT EXISTS uq_table_session_active_per_table
  ON table_session(tableid)
  WHERE status IN ('open','bill_requested');

CREATE INDEX IF NOT EXISTS idx_table_session_company_outlet_status ON table_session(companyid, outletid, status);
