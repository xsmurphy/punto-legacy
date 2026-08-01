BEGIN;

-- ============================================================
-- F2 del roadmap admin (context/34-admin-saas-plan.md) — semáforo de
-- salud/adopción por tenant. Ver TenantHealthService.php para el cálculo.
--
-- Nota de numeración: la 107 ya la tomó 107_printer_binding_station.sql
-- (mismo día). Esta migración es la 108.
--
-- Columnas físicas en MINÚSCULA SIN comillas (mismo criterio que 72_finance.sql
-- — el wrapper DB arma INSERT/UPDATE con las keys del array de PHP sin
-- comillas; Postgres pliega identificadores sin comillas a minúscula).
--
-- Tablas admin — NO tenant-local: no llevan companyid como "scope de
-- aislamiento del propio row" en el sentido tenant (son leídas/escritas
-- cross-tenant desde el realm /admin), pero tenant_health/_history sí llevan
-- companyid explícito en cada fila porque son 1 fila (o N filas semanales)
-- POR tenant. platform_config es config global de la plataforma, sin
-- companyid — la reusa F6 (ver plan) para integraciones/flags de negocio.
-- ============================================================

-- ── tenant_health — snapshot vigente (cache, recomputado on-demand >6h) ────
CREATE TABLE IF NOT EXISTS tenant_health (
  companyid    uuid PRIMARY KEY,
  score        int NOT NULL,
  level        varchar(8) NOT NULL,
  signals      jsonb NOT NULL DEFAULT '{}'::jsonb,
  computed_at  timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT tenant_health_level_chk CHECK (level IN ('green', 'yellow', 'red'))
);

-- ── tenant_health_history — snapshot semanal (línea histórica de score) ────
CREATE TABLE IF NOT EXISTS tenant_health_history (
  companyid  uuid NOT NULL,
  week       date NOT NULL,            -- lunes de la semana ISO (date_trunc('week', ...))
  score      int NOT NULL,
  level      varchar(8) NOT NULL,
  signals    jsonb NOT NULL DEFAULT '{}'::jsonb,
  PRIMARY KEY (companyid, week),
  CONSTRAINT tenant_health_history_level_chk CHECK (level IN ('green', 'yellow', 'red'))
);

CREATE INDEX IF NOT EXISTS idx_tenant_health_history_company ON tenant_health_history (companyid, week DESC);

-- ── platform_config — config de plataforma key→jsonb (nombre genérico:  ───
-- F6 la reusa para integraciones/flags de negocio, ver plan cerrado).
CREATE TABLE IF NOT EXISTS platform_config (
  key         varchar(64) PRIMARY KEY,
  value       jsonb NOT NULL,
  updated_by  uuid,
  updated_at  timestamptz DEFAULT now()
);

-- Seed idempotente de los pesos default del score de salud (suman 100).
INSERT INTO platform_config (key, value)
VALUES (
  'tenantHealthWeights',
  '{"activity":30,"breadth":20,"depth":15,"team":10,"ai":10,"commercial":15}'::jsonb
)
ON CONFLICT (key) DO NOTHING;

COMMIT;
