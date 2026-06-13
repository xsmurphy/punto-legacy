-- 27_outlet_residuals_and_company_settings_audit.sql
-- (1) Demote columnas residuales de `outlet` que la Migración 14 dejó como
-- columnas pero no participan en queries SQL críticas: outletNextExpirationDate.
-- (2) Audit: verificar que NO existe tabla `setting` separada — el schema PG ya
-- consolidó settings + module + companyHours dentro de `company.config` JSONB
-- (ver db-schema-postgres.sql líneas 25-55). Esta migración es no-op en ese
-- aspecto y documenta el estado.
--
-- COUNTERS que SE QUEDAN en columnas (mismo argumento que register):
--   outletPurchaseOrderNo, outletOrderTransferNo — incrementados en hot path
--     (panel/a_purchase.php:128 hace pastNo + 1 → atómico solo si es columna).
--
-- Columna demoted al JSONB `data`:
--   outletNextExpirationDate — sin queries que la referencien. Se preserva
--     en `data` por si algún tenant la tenía cargada.
--
-- ⚠️ DEPLOY COORDINADO con _getTableSchema() en app/panel functions.php
-- (quitar outletNextExpirationDate del whitelist 'outlet').
--
-- Idempotente.

BEGIN;

-- ── (1) Outlet residuales ─────────────────────────────────────────────────

-- Backfill
UPDATE outlet
SET data = COALESCE(data, '{}'::jsonb) || jsonb_strip_nulls(jsonb_build_object(
        'outletNextExpirationDate', to_char(outletNextExpirationDate AT TIME ZONE 'UTC',
                                             'YYYY-MM-DD"T"HH24:MI:SS"Z"')
    ))
WHERE outletNextExpirationDate IS NOT NULL;

-- Drop
ALTER TABLE outlet DROP COLUMN IF EXISTS outletNextExpirationDate;

-- ── (2) Company / settings audit ──────────────────────────────────────────
--
-- En MySQL legacy existía una tabla `setting` separada con una fila por
-- companyId. El schema PG (db-schema-postgres.sql:30-55) la consolidó dentro
-- de `company.config` JSONB. Si la BD de algún tenant todavía tiene la tabla
-- (instalación antigua que migró desde MySQL crudo), la dropeamos acá.
--
-- IF EXISTS hace este DROP idempotente: en una BD limpia desde el schema PG
-- es no-op; en una BD migrada con tabla huérfana, la elimina sin error.
--
-- Si en el futuro se agregan columnas a company por features que requieran
-- WHERE/INDEX (ej. campos de plan/billing), van como columnas relacionales.
-- Todo lo demás (preferencias de UI, configuración fiscal extendida, flags
-- de módulos opcionales) va a `company.config` JSONB.

DROP TABLE IF EXISTS "setting";
DROP TABLE IF EXISTS "settings";
DROP TABLE IF EXISTS "module";
DROP TABLE IF EXISTS "modules";
DROP TABLE IF EXISTS "companyHours";

COMMIT;
