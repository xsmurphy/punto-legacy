CREATE TABLE IF NOT EXISTS "numbering_lease" (
  "leaseId"    UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId"  UUID         NOT NULL,
  "outletId"   UUID         NOT NULL,
  "registerId" UUID         NOT NULL,
  "invoiceNo"  INTEGER      NOT NULL,
  "leasedAt"   TIMESTAMPTZ  NOT NULL DEFAULT now(),
  "consumedAt" TIMESTAMPTZ,
  "expiresAt"  TIMESTAMPTZ  NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_lease_register_pending ON "numbering_lease"("registerId", "consumedAt");
CREATE UNIQUE INDEX IF NOT EXISTS uq_lease_invoice ON "numbering_lease"("registerId", "invoiceNo");
