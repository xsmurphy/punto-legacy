CREATE TABLE IF NOT EXISTS inventory_count (
    "inventoryCountId" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    "companyId" UUID NOT NULL,
    "outletId" UUID NOT NULL,
    "locationId" UUID NULL,
    "status" SMALLINT NOT NULL DEFAULT 1,
    "note" TEXT,
    "startedAt" TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    "finishedAt" TIMESTAMPTZ,
    "startedBy" UUID NOT NULL,
    "finishedBy" UUID
);
CREATE INDEX IF NOT EXISTS ix_inventory_count_company_outlet ON inventory_count("companyId", "outletId", "startedAt" DESC);
CREATE INDEX IF NOT EXISTS ix_inventory_count_status ON inventory_count("companyId", "status");

CREATE TABLE IF NOT EXISTS inventory_count_item (
    "inventoryCountItemId" UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    "inventoryCountId" UUID NOT NULL REFERENCES inventory_count("inventoryCountId") ON DELETE CASCADE,
    "itemId" UUID NOT NULL,
    "expectedQty" NUMERIC(14, 4) NOT NULL,
    "countedQty" NUMERIC(14, 4),
    "unitCost" NUMERIC(14, 4) NOT NULL DEFAULT 0,
    "countedAt" TIMESTAMPTZ,
    "countedBy" UUID,
    "difference" NUMERIC(14, 4) GENERATED ALWAYS AS ("countedQty" - "expectedQty") STORED
);
CREATE INDEX IF NOT EXISTS ix_inventory_count_item_session ON inventory_count_item("inventoryCountId");
CREATE INDEX IF NOT EXISTS ix_inventory_count_item_item ON inventory_count_item("itemId");
