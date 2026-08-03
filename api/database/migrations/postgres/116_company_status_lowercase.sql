-- 116_company_status_lowercase.sql
-- Mismatch de casing en company.status: el legacy (SignupService, loginPart,
-- checkCompanyStatus, login.php) escribía y comparaba 'Active' capitalizado,
-- mientras el admin F3 (CompanyAdminService) escribe y filtra en minúscula
-- ('active'|'suspended'|'cancelled'). En prod TODAS las filas estaban en
-- 'Active' → el filtro/report del admin con status='active' no matcheaba, y
-- un unsuspend() (escribe 'active') dejaba al tenant fuera del gate
-- checkCompanyStatus() que exigía 'Active'.
--
-- Canónico: minúscula (consistente con CompanyAdminService). Este mig
-- normaliza los datos y el CHECK impide que reaparezca cualquier otro valor.
-- El código legacy se actualiza en el mismo commit (functions.php,
-- SignupService, login.php).
--
-- Idempotente: lower() reejecutable; el CHECK se agrega solo si no existe.

BEGIN;

UPDATE company SET status = lower(status) WHERE status <> lower(status);

-- Filas con valores fuera del set conocido (NULL o basura histórica) se
-- normalizan según sus señales: suspended=1 manda, si no queda 'active'
-- (blocked es señal independiente de mora y no toca status).
UPDATE company
   SET status = CASE WHEN suspended = 1 THEN 'suspended' ELSE 'active' END
 WHERE status IS NULL
    OR status NOT IN ('active', 'suspended', 'cancelled');

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
         WHERE conname = 'company_status_allowed'
           AND conrelid = 'company'::regclass
    ) THEN
        ALTER TABLE company
            ADD CONSTRAINT company_status_allowed
            CHECK (status IN ('active', 'suspended', 'cancelled'));
    END IF;
END $$;

COMMIT;
