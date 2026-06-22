CREATE TABLE IF NOT EXISTS parked_sale (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId" UUID NOT NULL,
  "outletId" UUID NOT NULL,
  "userId" UUID NULL,
  data JSONB NOT NULL,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_parked_sale_user ON parked_sale ("companyId", "outletId", "userId", "createdAt" DESC);
