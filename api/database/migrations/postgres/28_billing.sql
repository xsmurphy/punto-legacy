-- 28_billing.sql
-- Módulo de facturación SaaS — créditos IA, solicitudes de cambio de plan.
--
-- (1) plans.ai_credits_monthly — créditos IA incluidos por mes en el plan.
-- (2) company."aiCreditsBalance" — saldo actual de créditos IA del tenant.
--     Casing camelCase con comillas: mismo patrón que smsCredit en la tabla
--     company (sin comillas, lowercase histórico). NOTA: los campos PG nuevos
--     que el brief pide en camelCase se crean con comillas para preservar la
--     convención; sin embargo, dado que company usa smsCredit SIN comillas
--     (lowercase en wire), usamos la misma convención: sin comillas.
--     aiCreditsBalance → sin comillas (ADOdb devuelve lowercase: "aicreditsbalance").
-- (3) ai_credit_ledger — registro de débitos/créditos de IA por tenant.
-- (4) billing_request — solicitudes de cambio de plan (tenant → admin aprueba).
--
-- Idempotente: todo con IF NOT EXISTS / DO NOTHING.

BEGIN;

-- ── (1) plans: columna de créditos IA mensuales ───────────────────────────
ALTER TABLE plans
  ADD COLUMN IF NOT EXISTS ai_credits_monthly INT NOT NULL DEFAULT 0;

-- ── (2) company: saldo de créditos IA ────────────────────────────────────
-- Sin comillas para que el wire (ADOdb lowercase) sea predecible.
ALTER TABLE company
  ADD COLUMN IF NOT EXISTS aiCreditsBalance INT NOT NULL DEFAULT 0;

-- ── (3) ai_credit_ledger ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ai_credit_ledger (
  id           UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId    UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  delta        INT          NOT NULL,
  balanceAfter INT          NOT NULL,
  reason       VARCHAR(120) NOT NULL,
  tokensIn     INT          NOT NULL DEFAULT 0,
  tokensOut    INT          NOT NULL DEFAULT 0,
  meta         JSONB        NOT NULL DEFAULT '{}',
  createdAt    TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_ai_credit_ledger_company_date
  ON ai_credit_ledger(companyId, createdAt);

-- ── (4) billing_request ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS billing_request (
  id                 UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId          UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  requestedPlanCode  SMALLINT     NOT NULL,
  currentPlanCode    SMALLINT,
  status             VARCHAR(12)  NOT NULL DEFAULT 'pending',
  note               TEXT,
  createdAt          TIMESTAMPTZ  NOT NULL DEFAULT now(),
  resolvedAt         TIMESTAMPTZ,
  resolvedBy         VARCHAR(120)
);

CREATE INDEX IF NOT EXISTS idx_billing_request_status_date
  ON billing_request(status, createdAt);

CREATE INDEX IF NOT EXISTS idx_billing_request_company
  ON billing_request(companyId);

COMMIT;
