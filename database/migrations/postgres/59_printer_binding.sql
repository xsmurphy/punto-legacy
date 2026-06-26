CREATE TABLE IF NOT EXISTS "printer_binding" (
  "id"           UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId"    UUID         NOT NULL,
  "outletId"     UUID         NOT NULL,
  "registerId"   UUID         NOT NULL,
  "name"         VARCHAR(120) NOT NULL,
  "color"        VARCHAR(9)   NOT NULL DEFAULT '#7bd148',
  "transport"    VARCHAR(20)  NOT NULL,
  "vendorId"     INTEGER,
  "productId"    INTEGER,
  "deviceLabel"  VARCHAR(255),
  "mode"         VARCHAR(20)  NOT NULL DEFAULT 'escpos',
  "templateId"   UUID,
  "paperWidthMm" SMALLINT     NOT NULL DEFAULT 80,
  "copies"       SMALLINT     NOT NULL DEFAULT 1,
  "openDrawer"   BOOLEAN      NOT NULL DEFAULT FALSE,
  "autoPrint"    BOOLEAN      NOT NULL DEFAULT FALSE,
  "printDelay"   INTEGER      NOT NULL DEFAULT 0,
  "categoryIds"  JSONB        NOT NULL DEFAULT '[]'::jsonb,
  "docTypes"     JSONB        NOT NULL DEFAULT '[]'::jsonb,
  "createdAt"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
  "updatedAt"    TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_pb_register ON "printer_binding"("registerId");
CREATE INDEX IF NOT EXISTS idx_pb_tenant   ON "printer_binding"("companyId", "outletId");
