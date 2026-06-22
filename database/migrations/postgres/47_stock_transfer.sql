CREATE TABLE IF NOT EXISTS stock_transfer (
    "stockTransferId" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    "companyId" UUID NOT NULL,
    "fromOutletId" UUID NOT NULL,
    "fromLocationId" UUID NULL,
    "toOutletId" UUID NOT NULL,
    "toLocationId" UUID NULL,
    "status" SMALLINT NOT NULL DEFAULT 1,
    "note" TEXT,
    "createdAt" TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    "createdBy" UUID NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_stock_transfer_company_date ON stock_transfer("companyId", "createdAt" DESC);
CREATE INDEX IF NOT EXISTS ix_stock_transfer_from ON stock_transfer("companyId", "fromOutletId", "createdAt" DESC);
CREATE INDEX IF NOT EXISTS ix_stock_transfer_to ON stock_transfer("companyId", "toOutletId", "createdAt" DESC);

CREATE TABLE IF NOT EXISTS stock_transfer_item (
    "stockTransferItemId" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    "stockTransferId" UUID NOT NULL REFERENCES stock_transfer("stockTransferId") ON DELETE CASCADE,
    "itemId" UUID NOT NULL,
    "qty" NUMERIC(14, 4) NOT NULL,
    "unitCost" NUMERIC(14, 4) NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS ix_stock_transfer_item_transfer ON stock_transfer_item("stockTransferId");
CREATE INDEX IF NOT EXISTS ix_stock_transfer_item_item ON stock_transfer_item("itemId");
