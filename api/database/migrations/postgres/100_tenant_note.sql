-- 100_tenant_note.sql
-- F3 (context/34-admin-saas-plan.md) — notas internas del tenant en /admin.
-- Un admin escribe notas libres sobre un tenant (seguimiento comercial,
-- incidentes, contexto de soporte). Solo el autor puede borrar su nota.
-- Idempotente: IF NOT EXISTS.

BEGIN;

CREATE TABLE IF NOT EXISTS tenant_note (
  "noteId"    UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId" UUID         NOT NULL,
  "authorId"  UUID         NOT NULL,
  body        TEXT         NOT NULL,
  "createdAt" TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_tenant_note_company_created
  ON tenant_note ("companyId", "createdAt" DESC);

COMMIT;
