-- 44_giftcard.sql: gift cards — tabla para validación y consumo en POS
CREATE TABLE IF NOT EXISTS giftcard (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId" UUID NOT NULL,
  code VARCHAR(64) NOT NULL,
  "initialBalance" NUMERIC(14,2) NOT NULL DEFAULT 0,
  "currentBalance" NUMERIC(14,2) NOT NULL DEFAULT 0,
  "expiresAt" TIMESTAMPTZ NULL,
  "usedAt" TIMESTAMPTZ NULL,
  "usedByTransactionId" UUID NULL,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE ("companyId", code)
);

CREATE INDEX IF NOT EXISTS idx_giftcard_company ON giftcard ("companyId");
