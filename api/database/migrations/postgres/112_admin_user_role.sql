-- Migration: roles de admin de plataforma (context/34-admin-saas-plan.md F6 §1).
--
-- 'owner'   → todo (planes, módulos, llaves, admins, acciones de tenant, notas).
-- 'support' → sales + acciones de tenant (suspend/extend/credit_adjust/impersonar).
-- 'sales'   → lee tenants/salud/notas; escribe SOLO notas.
--
-- Jerarquía (ver adminRequireRole() en api/lib/Auth/AdminAuth.php):
--   sales(1) < support(2) < owner(3) — support incluye todo lo de sales, owner todo.
--
-- Default de columna 'support' (no 'owner'): a propósito. El PRIMER admin
-- existente (el más antiguo) se backfillea a 'owner' explícitamente abajo —
-- cualquier OTRO admin preexistente queda en el default 'support' y el owner
-- reasigna manualmente desde /admin/users si corresponde. Nunca dejamos la
-- plataforma sin owner tras la migración.
BEGIN;

ALTER TABLE admin_user
  ADD COLUMN IF NOT EXISTS role varchar(10) NOT NULL DEFAULT 'support';

ALTER TABLE admin_user
  DROP CONSTRAINT IF EXISTS admin_user_role_chk;
ALTER TABLE admin_user
  ADD CONSTRAINT admin_user_role_chk CHECK (role IN ('owner', 'support', 'sales'));

-- Backfill: el admin más antiguo (primero creado) queda 'owner'.
UPDATE admin_user
   SET role = 'owner'
 WHERE adminId = (SELECT adminId FROM admin_user ORDER BY created_at ASC, adminId ASC LIMIT 1);

COMMIT;
