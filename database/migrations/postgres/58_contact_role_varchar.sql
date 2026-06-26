-- Migration 58: contact.role smallint → varchar(64)
-- Razón: con el nuevo sistema de roles (RoleService + taxonomy), los roles custom
-- tienen UUID como id. UsersService.update guarda roleId directo en contact.role.
-- smallint no admite UUIDs → falla con 500 al guardar usuarios con role custom.
-- VARCHAR admite tanto legacy int (como string "1") como UUID custom.
--
-- BLOQUEANTE resuelto: el índice parcial idx_contact_phone_tenant_unique
-- (migration 12) tiene predicado `role = ANY(ARRAY[0,1,2,7])` que compara role
-- con integers. PG rechaza el ALTER COLUMN TYPE mientras ese índice exista
-- (operator does not exist: character varying = integer). Hay que DROP el índice,
-- hacer el ALTER, y recrearlo con comparación de strings.

BEGIN;

DROP INDEX IF EXISTS idx_contact_phone_tenant_unique;

ALTER TABLE contact ALTER COLUMN role TYPE VARCHAR(64) USING role::text;

-- Recrear el índice con role como string (mismo propósito: unicidad de teléfono
-- por tenant para contactos tipo usuario con rol legacy 0/1/2/7).
CREATE UNIQUE INDEX idx_contact_phone_tenant_unique
  ON contact (contactphone)
  WHERE contactphone IS NOT NULL
    AND contactphone::text <> ''
    AND type = 0
    AND role IN ('0', '1', '2', '7');

COMMIT;
