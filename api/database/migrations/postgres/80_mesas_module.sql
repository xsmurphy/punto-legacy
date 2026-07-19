-- 80_mesas_module.sql
-- Módulo de Espacios F0+F1 (context/15-espacios-module-plan.md, sobre el core
-- de Órdenes O0/O1 — context/24-orders-module-plan.md).
--
-- Nombres ya en terminología "Espacios" (rename mesas→espacios, mig 81) —
-- este archivo se editó in-place para que un fresh install cree los nombres
-- nuevos directamente, sin pasar por los nombres viejos. Un entorno que ya
-- había corrido esta mig con los nombres viejos (table_sector/dining_table/
-- table_session) migra con la 81, que es idempotente para ambos casos.
--
-- Tres tablas nuevas — capa espacial encima de `pos_order`. `pos_order.
-- spacesessionid` (mig 79) es el link: una `space_session` agrupa una o más
-- `pos_order` de la misma mesa/silla/habitación/ronda de servicio.
--
--   - space_sector: zonas de agrupación (Terraza, Salón, Barra, Planta alta)
--     por outlet.
--   - space: el espacio físico como entidad — mesa, silla de atención,
--     habitación, box, según el rubro del comercio. posx/posy/width/height
--     NULL = sin layout custom → el front la ubica en la grilla numerada
--     default (F1, editor de layout).
--   - space_session: instancia de ocupación (abrir espacio → sesión activa).
--     Índice único parcial garantiza una sola sesión open|bill_requested por
--     espacio — respalda SpaceSessionService.open() contra condiciones de
--     carrera sin lock explícito.
--
-- Reservas / space_assignment / space_settlement (context/15 §2.4-2.6) quedan
-- para F4/F3 — fuera de alcance de F0+F1. La cuenta/cobro de la sesión (F2)
-- tampoco se implementa acá: SpaceSessionService.close() solo cierra el
-- registro, no factura.
--
-- Todo lowercase sin comillas (patrón migs 72/76/79).

CREATE TABLE IF NOT EXISTS space_sector (
  sectorid    UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid   UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid    UUID           NOT NULL,
  name        VARCHAR(80)    NOT NULL,
  sort        INT            NOT NULL DEFAULT 0,
  status      SMALLINT       NOT NULL DEFAULT 1,
  created_at  TIMESTAMPTZ    NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_space_sector_company_outlet ON space_sector(companyid, outletid);

CREATE TABLE IF NOT EXISTS space (
  tableid     UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid   UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid    UUID           NOT NULL,
  -- NOT NULL a propósito: todo espacio SIEMPRE pertenece a un sector — no
  -- existe "espacio sin sector" (decisión del owner, mig 82). El backend
  -- garantiza un sector default ("Salón") por outlet vía
  -- SpaceSectorService::ensureDefaultSector() antes de cualquier create/
  -- bulkCreate sin sectorId explícito. ON DELETE RESTRICT (no SET NULL,
  -- incompatible con NOT NULL) — los sectores se soft-deletean
  -- (status=0), nunca se hard-borran desde la app.
  sectorid    UUID           NOT NULL REFERENCES space_sector(sectorid) ON DELETE RESTRICT,
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

CREATE INDEX IF NOT EXISTS idx_space_company_outlet ON space(companyid, outletid);
CREATE INDEX IF NOT EXISTS idx_space_sector          ON space(sectorid);

CREATE TABLE IF NOT EXISTS space_session (
  sessionid          UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid          UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid           UUID           NOT NULL,
  tableid            UUID           NOT NULL REFERENCES space(tableid) ON DELETE CASCADE,
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

-- Una sola sesión activa (open|bill_requested) por espacio — el índice único
-- parcial es la fuente de verdad de la invariante, no un check applicativo;
-- SpaceSessionService.open() confía en la violación de constraint para
-- devolver un error claro ante la carrera de dos aperturas concurrentes.
CREATE UNIQUE INDEX IF NOT EXISTS uq_space_session_active_per_space
  ON space_session(tableid)
  WHERE status IN ('open','bill_requested');

CREATE INDEX IF NOT EXISTS idx_space_session_company_outlet_status ON space_session(companyid, outletid, status);
