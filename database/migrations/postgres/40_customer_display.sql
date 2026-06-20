-- 40_customer_display.sql
-- Tabla customer_display: dispositivo (pantalla externa al cliente) pareado
-- a una caja específica vía token persistente. Mismo modelo que `device`
-- (mig 11) pero para Checkout Screens.
--
-- Revocación: el panel puede listar y desactivar pantallas (status=0). El
-- middleware del API valida status=1 + busca por SHA256 del token enviado.

BEGIN;

CREATE TABLE IF NOT EXISTS customer_display (
  id          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId   UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  registerId  UUID         NOT NULL,
  name        TEXT         NOT NULL DEFAULT '',
  tokenHash   TEXT         NOT NULL,
  ipFirst     INET,
  ipLast      INET,
  lastSeenAt  TIMESTAMPTZ,
  status      SMALLINT     NOT NULL DEFAULT 1,
  revokedAt   TIMESTAMPTZ,
  revokedBy   UUID,
  createdAt   TIMESTAMPTZ  NOT NULL DEFAULT now(),
  CHECK (status IN (0, 1))
);

CREATE INDEX IF NOT EXISTS idx_customer_display_company ON customer_display(companyId);
CREATE INDEX IF NOT EXISTS idx_customer_display_token   ON customer_display(tokenHash) WHERE status = 1;

COMMIT;
