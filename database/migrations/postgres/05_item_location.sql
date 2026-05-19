-- Migration: tabla itemLocation — relación N-a-N entre item y taxonomy(location)
--
-- Permite que un producto viva en N depósitos dentro de N outlets. Antes el
-- modelo solo soportaba 1 depósito default por item (item.locationId). El stock
-- ya era multi-depósito a nivel movement (stock.locationId), pero faltaba
-- declarar dónde "vive" cada item.
--
-- isDefault marca cuál es el depósito preferido para ventas/producción en
-- cada outlet (constraint parcial unique).
--
-- Backfill: para cada item con item.locationId NOT NULL, se inserta una fila
-- en itemLocation con isDefault=TRUE. outletId se resuelve via la taxonomy.
--
-- Idempotente.

CREATE TABLE IF NOT EXISTS itemLocation (
    itemLocationId UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    itemId         UUID         NOT NULL REFERENCES item(itemId)         ON DELETE CASCADE,
    locationId     UUID         NOT NULL REFERENCES taxonomy(taxonomyId) ON DELETE CASCADE,
    outletId       UUID         NOT NULL REFERENCES outlet(outletId),
    isDefault      BOOLEAN      NOT NULL DEFAULT FALSE,
    companyId      UUID         NOT NULL REFERENCES company(companyId),
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (itemId, locationId)
);

-- Solo UN default por combinación (itemId, outletId)
CREATE UNIQUE INDEX IF NOT EXISTS idx_itemloc_default_per_outlet
    ON itemLocation (itemId, outletId) WHERE isDefault = TRUE;

CREATE INDEX IF NOT EXISTS idx_itemloc_item     ON itemLocation(itemId);
CREATE INDEX IF NOT EXISTS idx_itemloc_location ON itemLocation(locationId);
CREATE INDEX IF NOT EXISTS idx_itemloc_outlet   ON itemLocation(outletId);
CREATE INDEX IF NOT EXISTS idx_itemloc_company  ON itemLocation(companyId);

-- Backfill: items con locationId configurado pasan a itemLocation como default
INSERT INTO itemLocation (itemId, locationId, outletId, isDefault, companyId)
SELECT
    i.itemId,
    i.locationId,
    t.outletId,
    TRUE,
    i.companyId
FROM item i
JOIN taxonomy t ON t.taxonomyId = i.locationId
WHERE i.locationId IS NOT NULL
  AND i.companyId  IS NOT NULL
  AND t.outletId   IS NOT NULL
  AND t.taxonomyType = 'location'
ON CONFLICT (itemId, locationId) DO NOTHING;
