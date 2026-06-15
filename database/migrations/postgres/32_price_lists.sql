-- ============================================================
-- Migration 32: Listas de precios
-- ============================================================

-- 1. price_list: encabezado de la lista
CREATE TABLE price_list (
  priceListId        UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  priceListName      VARCHAR(120)  NOT NULL,
  -- defaultAdjustment: % de ajuste global. Negativo = descuento, positivo = recargo.
  -- Ej: -10.00 = 10% off; +15.00 = 15% de recargo (para plataformas delivery).
  defaultAdjustment  DECIMAL(6,2)  NOT NULL DEFAULT 0,
  validFrom          TIMESTAMPTZ,
  validTo            TIMESTAMPTZ,
  status             BOOLEAN       NOT NULL DEFAULT true,
  companyId          UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  createdAt          TIMESTAMPTZ   NOT NULL DEFAULT now(),
  updatedAt          TIMESTAMPTZ   NOT NULL DEFAULT now()
);
CREATE INDEX idx_price_list_company ON price_list(companyId, status);

-- 2. price_list_item: override por ítem
-- fixedPrice y itemAdjustment son mutuamente excluyentes.
-- fixedPrice = precio absoluto (ignora defaultAdjustment y itemAdjustment).
-- itemAdjustment = % override para este ítem (reemplaza defaultAdjustment).
CREATE TABLE price_list_item (
  priceListItemId  UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
  priceListId      UUID          NOT NULL REFERENCES price_list(priceListId) ON DELETE CASCADE,
  itemId           UUID          NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  fixedPrice       DECIMAL(15,2),
  itemAdjustment   DECIMAL(6,2),
  companyId        UUID          NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  createdAt        TIMESTAMPTZ   NOT NULL DEFAULT now(),
  CONSTRAINT uq_price_list_item UNIQUE (priceListId, itemId)
);
CREATE INDEX idx_price_list_item_list ON price_list_item(priceListId, companyId);
CREATE INDEX idx_price_list_item_item ON price_list_item(itemId, companyId);
