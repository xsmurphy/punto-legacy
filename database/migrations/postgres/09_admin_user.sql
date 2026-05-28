-- Migration: tabla admin_user — super-admins de PLATAFORMA (admin realm), separados de los tenants.
--
-- Decisión de arquitectura (2026-05-28): el "admin" deja de ser un tenant especial (el flag SAAS_ADM
-- sobre la empresa MASTER_COMPANY_ID) y pasa a ser un usuario propio en su propia tabla, con su propio
-- login en /admin. Esto da aislamiento real: ningún query/tenancy de tenant toca a los admins, y el
-- esquema de password es independiente.
--
--   - password con bcrypt (password_hash / PASSWORD_DEFAULT), NO el sha256+salt+HASH_TIMES de `contact`.
--   - status: 1 activo, 0 desactivado (no se borra físicamente → auditoría).
--   - createdBy: quién lo creó (self-FK), para auditoría del CRUD de admins.
--   - email único case-insensitive (índice sobre lower(email)).
--   - admin_user NO tiene companyId: un admin de plataforma no pertenece a ninguna empresa.
--
-- El super-admin inicial se siembra desde .env vía panel/admin/bootstrap_seed.php (correr DESPUÉS de
-- esta migración). Idempotente.

CREATE TABLE IF NOT EXISTS admin_user (
    adminId      UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    email        TEXT        NOT NULL,
    passwordHash TEXT        NOT NULL,
    name         TEXT        NOT NULL DEFAULT '',
    status       SMALLINT    NOT NULL DEFAULT 1,
    createdBy    UUID        NULL REFERENCES admin_user(adminId) ON DELETE SET NULL,
    lastLoginAt  TIMESTAMPTZ NULL,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (length(trim(email)) > 0),
    CHECK (status IN (0, 1))
);

-- Email único case-insensitive (el login normaliza con lower()).
CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_user_email  ON admin_user (lower(email));
CREATE INDEX        IF NOT EXISTS idx_admin_user_status ON admin_user (status);
