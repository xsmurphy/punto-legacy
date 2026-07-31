-- 106_signup_otp.sql
-- OTP propio del signup — reemplaza los scripts legacy borrados
-- `2fapin.php` / `phonevalidator.php` (limpieza 2026-06-29), que dejaban
-- `api/v1/signup/start.php` muerto en prod. Ver context/06-infraestructura.md
-- (env `SIGNUP_OTP`) y api/lib/Auth/SignupOtp.php (única fuente de verdad
-- para issue/check).
--
-- Sin companyid: el signup es pre-tenant (todavía no existe la company).
-- phone = E.164 SIN '+' (convención storage). PK simple: un código activo
-- por número, se pisa con UPSERT en cada reenvío.
--
-- Columnas físicas en MINÚSCULA SIN comillas (mismo motivo que 72_finance.sql:
-- ncmInsert/ncmUpdate arman INSERT/UPDATE con las keys del array PHP sin
-- comillas, y Postgres pliega identificadores sin comillas a minúscula).
BEGIN;

CREATE TABLE IF NOT EXISTS signup_otp (
  phone       varchar(32) PRIMARY KEY,   -- E.164 sin '+', ej: 595991742353
  codehash    varchar(64) NOT NULL,      -- sha256(código) — el código plano nunca se guarda
  expires_at  timestamptz NOT NULL,
  attempts    int NOT NULL DEFAULT 0,    -- rate limit: máx 5 intentos, ver SignupOtp::check()
  created_at  timestamptz NOT NULL DEFAULT now()
);

COMMIT;
