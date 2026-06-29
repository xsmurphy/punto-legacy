BEGIN;

CREATE TABLE IF NOT EXISTS auth_session (
  "sessionId"  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  "tokenHash"  char(64) NOT NULL,                 -- sha256 hex del token crudo (nunca se guarda el crudo)
  realm        varchar(16) NOT NULL,              -- 'panel' | 'pos-app' | 'admin' | 'screen'
  "companyId"  uuid,                              -- null para admin (cross-tenant)
  "userId"     uuid,                              -- contactId (tenant) o adminId (admin)
  "deviceId"   uuid,                              -- refiere device.deviceId; null en panel/admin
  "outletId"   uuid,
  "registerId" uuid,
  "roleId"     varchar(64),                       -- role int-as-string legacy o UUID
  module       varchar(32),                       -- 'pos' | 'screen' | 'panel' | 'admin'
  status       smallint NOT NULL DEFAULT 1,       -- 1=activa, 0=revocada
  meta         jsonb NOT NULL DEFAULT '{}'::jsonb,
  "createdAt"  timestamptz NOT NULL DEFAULT now(),
  "lastSeenAt" timestamptz,
  "expiresAt"  timestamptz,                        -- null = nunca expira (device POS eterno)
  "revokedAt"  timestamptz,
  "revokedBy"  uuid,
  "userAgent"  text,
  "ipFirst"    varchar(64),
  "ipLast"     varchar(64)
);

-- Lookup hot-path: igualdad por hash. UNIQUE para idempotencia + plan index-only.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_auth_session_token ON auth_session ("tokenHash");
-- Listado de sesiones por empresa (UI de revocación).
CREATE INDEX IF NOT EXISTS idx_auth_session_company ON auth_session ("companyId", realm, status);
-- Revocación por device (cuando se revoca un device se revocan sus sesiones).
CREATE INDEX IF NOT EXISTS idx_auth_session_device ON auth_session ("deviceId") WHERE "deviceId" IS NOT NULL;

COMMIT;
