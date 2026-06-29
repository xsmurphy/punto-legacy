-- 29_admin_audit.sql
-- Tabla de auditoría para acciones del super-admin (realm /admin).
--
-- Registra quién hizo qué, sobre qué entidad, cuándo y desde qué IP.
-- adminEmail se desnormaliza para preservar el historial aunque el admin_user
-- sea eliminado posteriormente.
--
-- Idempotente: todo con IF NOT EXISTS.

BEGIN;

CREATE TABLE IF NOT EXISTS admin_audit (
  id           UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "adminId"    UUID,
  "adminEmail" VARCHAR(180),
  action       VARCHAR(40)  NOT NULL,
  "targetType" VARCHAR(20),
  "targetId"   VARCHAR(64),
  "targetName" VARCHAR(200),
  meta         JSONB        NOT NULL DEFAULT '{}',
  ip           VARCHAR(64),
  "createdAt"  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_admin_audit_created_at
  ON admin_audit("createdAt");

CREATE INDEX IF NOT EXISTS idx_admin_audit_action
  ON admin_audit(action);

COMMIT;
