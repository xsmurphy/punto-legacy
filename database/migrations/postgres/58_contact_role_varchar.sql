-- Migration 58: contact.role smallint → varchar(64)
-- Razón: con el nuevo sistema de roles (RoleService + taxonomy), los roles custom
-- tienen UUID como id. UsersService.update guarda roleId directo en contact.role.
-- smallint no admite UUIDs → falla con 500 al guardar usuarios con role custom.
-- VARCHAR admite tanto legacy int (como string "1") como UUID custom.
-- Los call sites ya usan role::text = ? (RoleService, roles/users.php) o serán
-- ajustados (functions.php:2566 cambia de `role = 1` a `role = '1'`).

ALTER TABLE contact ALTER COLUMN role TYPE VARCHAR(64) USING role::text;
