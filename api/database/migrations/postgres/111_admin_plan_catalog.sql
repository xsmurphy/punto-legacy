-- 111_admin_plan_catalog.sql
-- F4 (context/34-admin-saas-plan.md) — CRUD de planes desde /admin.
--
-- `plans.archived` habilita el modelo de versionado NO retroactivo: metadata
-- cosmética (nombre) se edita in-place; cualquier cambio de precio/duración/
-- límites/features/ai_credits_monthly crea un plan NUEVO (plan_code nuevo) y
-- archiva el viejo (archived=1). Los tenants vigentes conservan su plan_code
-- viejo — company.plan no se toca — así que siguen operando y facturando
-- igual. Los planes archivados no aparecen para asignar a nuevos tenants.
-- Ver PlanAdminService::update() para la implementación de la regla.
--
-- `ai_credit_package` — catálogo de paquetes de créditos IA comprables
-- (F4 §3). Solo el catálogo — el flujo de compra del tenant es otra fase.
--
-- Idempotente.

BEGIN;

ALTER TABLE plans ADD COLUMN IF NOT EXISTS archived SMALLINT NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS plans_archived_idx ON plans (archived);

CREATE TABLE IF NOT EXISTS ai_credit_package (
    packageid   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name        VARCHAR(120) NOT NULL,
    credits     INTEGER NOT NULL CHECK (credits > 0),
    price       NUMERIC(14,2) NOT NULL CHECK (price >= 0),
    archived    SMALLINT NOT NULL DEFAULT 0,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS ai_credit_package_archived_idx ON ai_credit_package (archived);

COMMIT;
