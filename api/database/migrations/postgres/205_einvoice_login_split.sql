-- 205_einvoice_login_split.sql
-- Facturación electrónica — el emisor tiene DOS identidades, no una.
--
-- (La numeración 203 pedida en la auditoría ya estaba tomada por
-- `203_addon_group_qty_mode.sql` y `204_einvoice_numbering_mismatch.sql`;
-- esta es la primera libre. Las migraciones aplicadas no se editan.)
--
-- ── El problema ──────────────────────────────────────────────────────
--
-- `einvoice_account.phone_enc` (mig 95, redocumentada en la mig 100) venía
-- alimentando DOS cosas distintas que resultaron ser datos DISTINTOS:
--
--   1. El header `phonenumber` de TODAS las llamadas autenticadas. La mig
--      100 verificó contra la API real (2026-07-30) que ese header es el
--      UserName del usuario y que, para usuarios creados por
--      CreateExternal, UserName = EMAIL.
--
--   2. La identidad de `POST /api/account/PhoneLogin`, que el fix
--      2d828eaf (2026-09-07) verificó que es el CELULAR del dueño — con
--      el email devuelve 500 y el emisor queda sin poder autenticarse.
--
-- Mientras se creyó que era un solo dato, cada fix rompía al otro: la mig
-- 100 puso el email (header ok, PhoneLogin roto) y 2d828eaf puso el
-- celular (PhoneLogin ok, header con el dato equivocado). Son dos
-- identidades y necesitan dos columnas.
--
-- ── El reparto que establece esta migración ──────────────────────────
--
--   `login_enc` → identidad de LOGIN (el email/UserName). Es la que viaja
--                 en el header `phonenumber` de toda llamada y en `/Token`.
--   `phone_enc` → el CELULAR del dueño. Solo lo usa PhoneLogin.
--
-- NO se hace backfill en SQL: los dos valores están CIFRADOS con la clave
-- de `CredentialVault` (aplicación), así que Postgres no puede leerlos
-- para decidir cuál es cuál. La reparación de las filas ya provisionadas
-- vive en `EmitterIdentity` (PHP), que descifra, detecta el caso legacy y
-- reescribe la fila en su primer uso — idempotente y sin ventana de
-- indisponibilidad. Ver el docblock de esa clase para los tres casos
-- (fila nueva, fila mig-100 con el email en phone_enc, fila 2d828eaf con
-- el celular en phone_enc y el email en `username`).
--
-- La columna es NULL-able a propósito: NULL significa "todavía sin
-- reparar", y es la señal que dispara el self-healing.
--
-- ── securityCode estable por documento ───────────────────────────────
--
-- `einvoice_document.security_code`: los 9 dígitos del componente 10 del
-- CDC. Se generaban con `Cdc::securityCode()` DENTRO del mapper, o sea una
-- vez por INTENTO. Un timeout después de que Factomate ya creó el
-- documento hacía que el reintento saliera con un securityCode distinto y
-- por lo tanto con otro CDC para la misma venta — que es exactamente el
-- rechazo 1002 (duplicado) de SIFEN. Se congela en la fila del outbox en
-- el primer intento y se reusa en todos los reintentos del MISMO
-- documento. Una reemisión (mig 201, `superseded_by`) es una fila NUEVA:
-- arranca en NULL y genera su propio código, que es lo correcto.
--
-- VARCHAR(9): `Cdc::securityCode()` devuelve exactamente 9 dígitos con
-- padding de ceros a la izquierda — se guarda como texto, no como entero,
-- porque los ceros a la izquierda son significativos para el CDC.
--
-- Todo lowercase sin comillas (convención del repo). IF NOT EXISTS en
-- todo: la migración tiene que poder correr dos veces sin romper.

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS login_enc TEXT;

COMMENT ON COLUMN einvoice_account.login_enc IS
  'Identidad de LOGIN del emisor (UserName = email), cifrada. Va en el header phonenumber de toda llamada y en /Token. NO confundir con phone_enc, que es el celular del dueño y solo sirve para PhoneLogin. NULL = fila legacy sin reparar (EmitterIdentity la repara en el primer uso).';

COMMENT ON COLUMN einvoice_account.phone_enc IS
  'CELULAR del dueño, cifrado. Es la identidad de POST /api/account/PhoneLogin (verificado 2026-09-07). NO es el header phonenumber de las llamadas normales — ese es login_enc. En filas anteriores a la mig 205 puede contener el email: EmitterIdentity lo detecta y lo repara.';

ALTER TABLE einvoice_document
  ADD COLUMN IF NOT EXISTS security_code VARCHAR(9);

COMMENT ON COLUMN einvoice_document.security_code IS
  'Los 9 dígitos del securityCode (componente 10 del CDC) congelados en el primer intento de emisión. Se reusan en cada reintento del MISMO documento para que el CDC no cambie entre intentos (un reintento con otro CDC sobre un documento ya creado en el proveedor es el rechazo 1002 de SIFEN). Una reemisión es una fila nueva y genera el suyo.';
