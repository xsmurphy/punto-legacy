-- 35_tenant_audit.sql
-- Tabla de auditoría de acciones mutantes de usuarios tenant.
-- Registra quién hizo qué, cuándo, desde qué IP, para qué comercio.
-- Idempotente: IF NOT EXISTS.

BEGIN;

CREATE TABLE IF NOT EXISTS tenant_audit (
  id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId" UUID         NOT NULL,
  "userId"    UUID,
  "outletId"  UUID,
  realm       VARCHAR(20),
  method      VARCHAR(10),
  endpoint    VARCHAR(160),
  "targetId"  VARCHAR(64),
  meta        JSONB        DEFAULT '{}',
  ip          VARCHAR(64),
  "createdAt" TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_tenant_audit_company_created
  ON tenant_audit ("companyId", "createdAt" DESC);

CREATE INDEX IF NOT EXISTS idx_tenant_audit_created_at
  ON tenant_audit ("createdAt");

COMMIT;
