-- Migration 50: tabla device para POS dual-auth
-- Registra cada dispositivo POS emparejado con un tenant.
-- Sin FK constraints — la validación de ownership la hace DeviceAuth::validateJwt
-- y apiAuthPosContext en PHP.

CREATE TABLE IF NOT EXISTS device (
  "deviceId"     UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  "companyId"    UUID         NOT NULL,
  "outletId"     UUID,
  "registerId"   UUID,
  "userId"       UUID         NOT NULL,
  "deviceName"   VARCHAR(120) NOT NULL DEFAULT '',
  "userAgent"    VARCHAR(500),
  "ipFirst"      INET,
  "status"       SMALLINT     NOT NULL DEFAULT 1,
  "createdAt"    TIMESTAMPTZ  NOT NULL DEFAULT now(),
  "lastSeenAt"   TIMESTAMPTZ,
  "revokedAt"    TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_device_company_status ON device ("companyId", "status");
CREATE INDEX IF NOT EXISTS idx_device_register ON device ("registerId");
