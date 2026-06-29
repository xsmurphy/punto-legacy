BEGIN;
ALTER TABLE device_invitation
  ADD COLUMN IF NOT EXISTS auto_approve boolean NOT NULL DEFAULT false;
-- device_id ya existe (se setea hoy en approve); ahora lo seteamos también en create
-- para reconnect invitations, así open() sabe a qué device target re-emitir JWT.
CREATE INDEX IF NOT EXISTS idx_di_target_device ON device_invitation (device_id) WHERE auto_approve = true;
COMMIT;
