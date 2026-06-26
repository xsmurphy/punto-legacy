ALTER TABLE device ADD COLUMN IF NOT EXISTS browserlocalid VARCHAR(64);

-- Maximo 1 device ACTIVO por (companyId, registerId, browserLocalId).
-- Parcial (status=1 AND not null) para no chocar con rows legacy sin localId.
CREATE UNIQUE INDEX IF NOT EXISTS uq_device_browser_active
  ON device(companyid, registerid, browserlocalid)
  WHERE status = 1 AND browserlocalid IS NOT NULL;
