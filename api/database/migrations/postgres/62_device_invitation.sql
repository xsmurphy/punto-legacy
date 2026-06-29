CREATE TABLE IF NOT EXISTS device_invitation (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  company_id      UUID NOT NULL,
  created_by      UUID NOT NULL,
  module          VARCHAR(20) NOT NULL,
  outlet_id       UUID,
  register_id     UUID,
  device_name     VARCHAR(120),
  user_code       VARCHAR(9),
  status          VARCHAR(20) NOT NULL DEFAULT 'pending',
  opened_at       TIMESTAMPTZ,
  device_ua       TEXT,
  device_ip       INET,
  device_geo      VARCHAR(120),
  approved_at     TIMESTAMPTZ,
  approved_by     UUID,
  device_id       UUID,
  created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at      TIMESTAMPTZ NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_di_company_status ON device_invitation(company_id, status);
CREATE UNIQUE INDEX IF NOT EXISTS uq_di_user_code_active
  ON device_invitation(user_code)
  WHERE status IN ('pending','opened') AND user_code IS NOT NULL;
