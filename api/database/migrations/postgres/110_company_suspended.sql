-- 110_company_suspended.sql
-- P1 F3 review: `company.blocked` estaba sobrecargada con dos señales distintas
-- — mora/billing (independiente) y suspensión manual del admin (CompanyAdminService::
-- suspend()/unsuspend()) — y unsuspend() hardcodeaba blocked=0 sin importar el motivo
-- original. Un tenant bloqueado por mora que pasaba por suspend→unsuspend quedaba
-- desbloqueado, perdiendo la señal de mora.
--
-- Fix de raíz: columna propia `suspended`, independiente de `blocked`. suspend()/
-- unsuspend() ahora tocan status+suspended, nunca blocked — el estado de mora
-- queda intacto por construcción.
--
-- Idempotente: IF NOT EXISTS + data-fix reejecutable (WHERE status='suspended'
-- vuelve a dar el mismo resultado si se corre de nuevo).

BEGIN;

ALTER TABLE company ADD COLUMN IF NOT EXISTS suspended SMALLINT NOT NULL DEFAULT 0;

-- Data-fix: backfill para empresas ya suspendidas antes de esta migración
-- (status='suspended' es la señal existente hasta hoy).
UPDATE company SET suspended = 1 WHERE status = 'suspended';

COMMIT;
