-- 30_billing_dlocal.sql — Integración dLocal Go: packs de créditos + facturas.
--
-- (1) credit_pack    — catálogo de paquetes de créditos comprables (one-time).
-- (2) billing_invoice — facturas/pagos SaaS hacia Punto (pending → paid/failed).
-- (3) ai_credit_ledger.relatedInvoiceId + índice único parcial → idempotencia
--     anti doble-acreditación cuando dLocal reintenta el webhook.
--
-- Idempotente: IF NOT EXISTS en todo. Convención de columnas igual que la 28
-- (camelCase sin comillas → PG las pliega a minúsculas; el código lee con
-- fallback lower/camel).

-- ── (1) credit_pack ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS credit_pack (
  id           UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  slug         VARCHAR(40)   NOT NULL UNIQUE,
  name         VARCHAR(120)  NOT NULL,
  credits      INT           NOT NULL CHECK (credits > 0),
  priceUsd     NUMERIC(10,2) NOT NULL CHECK (priceUsd > 0),
  validityDays INT           NOT NULL DEFAULT 180,
  active       BOOLEAN       NOT NULL DEFAULT TRUE,
  sortOrder    INT           NOT NULL DEFAULT 0,
  createdAt    TIMESTAMPTZ   NOT NULL DEFAULT now()
);

-- Seed inicial de packs (precios placeholder — ajustables por el owner).
INSERT INTO credit_pack (slug, name, credits, priceUsd, sortOrder) VALUES
  ('pack_500',   'Pack pequeño', 500,    3.00,  1),
  ('pack_2000',  'Pack mediano', 2000,   9.00,  2),
  ('pack_10000', 'Pack grande',  10000, 39.00,  3)
ON CONFLICT (slug) DO NOTHING;

-- ── (2) billing_invoice ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS billing_invoice (
  id                UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId         UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  type              VARCHAR(20)   NOT NULL DEFAULT 'pack',
  amountUsd         NUMERIC(10,2) NOT NULL,
  currency          VARCHAR(8)    NOT NULL DEFAULT 'USD',
  status            VARCHAR(16)   NOT NULL DEFAULT 'pending'
                      CHECK (status IN ('pending','paid','failed','refunded','cancelled')),
  packId            UUID          REFERENCES credit_pack(id),
  provider          VARCHAR(30)   NOT NULL DEFAULT 'dlocal_go',
  providerInvoiceId VARCHAR(120),
  providerMetadata  JSONB         NOT NULL DEFAULT '{}',
  paidAt            TIMESTAMPTZ,
  createdAt         TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updatedAt         TIMESTAMPTZ   NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_billing_invoice_company_date
  ON billing_invoice(companyId, createdAt);
CREATE INDEX IF NOT EXISTS idx_billing_invoice_provider_lookup
  ON billing_invoice(provider, providerInvoiceId)
  WHERE providerInvoiceId IS NOT NULL;

-- ── (3) ai_credit_ledger: relación con factura + idempotencia ──────────────
ALTER TABLE ai_credit_ledger ADD COLUMN IF NOT EXISTS relatedInvoiceId UUID;

-- Un solo crédito por factura: si dLocal reintenta el webhook, el segundo
-- INSERT de tipo 'pack_purchase' viola el índice → se trata como ya-procesado.
CREATE UNIQUE INDEX IF NOT EXISTS uq_ai_credit_ledger_invoice_grant
  ON ai_credit_ledger(relatedInvoiceId)
  WHERE relatedInvoiceId IS NOT NULL AND reason = 'pack_purchase';
