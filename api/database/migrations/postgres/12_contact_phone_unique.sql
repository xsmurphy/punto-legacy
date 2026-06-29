-- Migration 12: UNIQUE phone global para tenants
--
-- Contexto: tenants login SOLO por contactPhone (admin realm usa admin_user/email).
-- Sin UNIQUE, dos contacts en companies distintas con el mismo phone hacen el
-- WHERE contactPhone=? LIMIT 1 no-determinístico → un user no puede entrar a su
-- cuenta correcta. Race conditions en signUp también podrían slip dos rows
-- duplicados aunque el check de aplicación los rechaza.
--
-- Restricción: única por contactPhone para filas que SON tenant login
-- (role IN (0,1,2,7) AND type=0 AND contactPhone <> ''). Esto excluye:
--   - role 3+ (managers/empleados/customers): pueden compartir phone con su tenant.
--   - type != 0 (records contables/transaccionales): no son users.
--   - contactPhone NULL o vacío: legacy que se irá poblando.

BEGIN;

-- Pre-flight: si ya hay duplicados, listar para que el operador decida qué hacer.
-- Si esto devuelve filas, ABORTAR y resolver manualmente antes de reaplicar.
DO $$
DECLARE
    dup_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO dup_count
    FROM (
        SELECT contactPhone
        FROM contact
        WHERE contactPhone IS NOT NULL
          AND contactPhone <> ''
          AND type = 0
          AND role IN (0,1,2,7)
        GROUP BY contactPhone
        HAVING COUNT(*) > 1
    ) dups;

    IF dup_count > 0 THEN
        RAISE EXCEPTION 'Migration 12 abort: % contactPhone duplicado(s) entre tenants. Resolver antes de aplicar.', dup_count;
    END IF;
END $$;

-- Drop el índice no-único viejo si existe (lo reemplazamos con el UNIQUE).
DROP INDEX IF EXISTS idx_contact_phone;

-- Índice UNIQUE parcial: phone único entre tenants activos.
-- "Parcial" porque excluye empleados (role 3+), records contables (type != 0) y phones vacíos.
CREATE UNIQUE INDEX idx_contact_phone_tenant_unique
    ON contact (contactPhone)
    WHERE contactPhone IS NOT NULL
      AND contactPhone <> ''
      AND type = 0
      AND role IN (0,1,2,7);

-- Mantener un índice no-único para los lookups por phone+company de empleados etc.
CREATE INDEX idx_contact_phone_company ON contact (contactPhone, companyId);

COMMIT;
