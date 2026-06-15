-- ============================================================
-- Migration 31: Packs / Combos de servicios
-- ============================================================

-- 1. pack_component: define los servicios incluidos en un pack
CREATE TABLE pack_component (
  packComponentId  UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  packItemId       UUID        NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  componentItemId  UUID        NOT NULL REFERENCES item(itemId),
  componentQty     SMALLINT    NOT NULL DEFAULT 1,
  sort             SMALLINT    NOT NULL DEFAULT 0,
  companyId        UUID        NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  createdAt        TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_pack_component_pack    ON pack_component(packItemId, companyId);
CREATE INDEX idx_pack_component_company ON pack_component(companyId);

-- 2. sold_pack: instancia de pack vendida a un cliente
-- status: 1=activo, 0=bloqueado/vencido, 2=consumido_completamente
CREATE TABLE sold_pack (
  soldPackId    UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  packItemId    UUID        NOT NULL REFERENCES item(itemId),
  contactId     UUID        NOT NULL REFERENCES contact(contactId),
  transactionId UUID        REFERENCES transaction(transactionId),
  outletId      UUID        REFERENCES outlet(outletId),
  companyId     UUID        NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  expiresAt     TIMESTAMPTZ NOT NULL,
  status        SMALLINT    NOT NULL DEFAULT 1,
  createdAt     TIMESTAMPTZ NOT NULL DEFAULT now(),
  updatedAt     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_sold_pack_contact ON sold_pack(contactId, companyId, status);
CREATE INDEX idx_sold_pack_company ON sold_pack(companyId, status);
CREATE INDEX idx_sold_pack_tx      ON sold_pack(transactionId);

-- 3. sold_pack_usage: cada canje individual de un servicio del pack
CREATE TABLE sold_pack_usage (
  usageId         UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  soldPackId      UUID        NOT NULL REFERENCES sold_pack(soldPackId) ON DELETE CASCADE,
  packComponentId UUID        NOT NULL REFERENCES pack_component(packComponentId),
  qty             SMALLINT    NOT NULL DEFAULT 1,
  performedBy     UUID        REFERENCES contact(contactId),
  outletId        UUID        REFERENCES outlet(outletId),
  companyId       UUID        NOT NULL,
  notes           TEXT,
  performedAt     TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_pack_usage_sold    ON sold_pack_usage(soldPackId);
CREATE INDEX idx_pack_usage_company ON sold_pack_usage(companyId);
CREATE INDEX idx_pack_usage_by      ON sold_pack_usage(performedBy) WHERE performedBy IS NOT NULL;
