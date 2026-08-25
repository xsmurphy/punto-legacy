-- 171_device_invitation_single_use.sql
--
-- Canje de un solo uso para device_invitation (incidente 2026-08-25).
--
-- ── El defecto que cierra ───────────────────────────────────────────────────
--
-- `DeviceInvitationService::status()` re-emitía un token de dispositivo NUEVO
-- en CADA consulta mientras la invitación estuviera en `approved`, sin marca
-- de consumida, sin límite de veces y sin chequear `expires_at` (la expiración
-- solo se evaluaba en `pending`/`opened`). El link de conexión viaja por
-- WhatsApp y queda en el chat: era un emisor de credenciales permanente para
-- ESA caja. Comprobado en prod el 2026-08-25: el device
-- 6cdf0baf-a507-4686-816d-6244bd5a0b00 tenía 3 sesiones `pos-app` activas
-- creadas en 6 segundos desde tres user-agents distintos (un Mac y dos
-- iPhones) — el mismo link abierto en tres navegadores.
--
-- Además de un problema de sesiones es un problema FISCAL: dos dispositivos
-- operando la misma caja rompen la exclusividad del punto de expedición
-- (context/29-numeracion-y-exclusividad-de-caja.md) y pueden emitir facturas
-- duplicadas con el mismo timbrado.
--
-- ── Qué agrega ──────────────────────────────────────────────────────────────
--
-- `consumed_at`    — sello del canje. El estado terminal nuevo es
--                    `status='consumed'`: la invitación entregó su token y ya
--                    no entrega otro. No hace falta tocar ningún CHECK porque
--                    `device_invitation.status` es varchar libre (mig 62).
--
-- `pairing_secret` — sha256 hex (64 chars) del secreto de sesión de pairing
--                    que `open()` genera en la PRIMERA apertura y devuelve UNA
--                    sola vez al navegador que abrió. Es lo que distingue "el
--                    mismo lector recargando la página" de "un segundo
--                    navegador con el mismo link". Se guarda HASHEADO, igual
--                    que `auth_session.tokenHash` (mig 69): la BD nunca ve el
--                    secreto crudo.
--
--                    Se descartó usar `device_ua`/`device_ip` para esto: en la
--                    red del comercio dos tablets comparten IP saliente y a
--                    menudo el mismo user-agent, así que darían falsos
--                    positivos justo entre los dos dispositivos que más
--                    importa distinguir.
--
-- ── Sobre las filas existentes ──────────────────────────────────────────────
--
-- NO se hace backfill ni se revoca nada: lo decide el owner. Las 67 filas que
-- hoy están en `approved` en prod quedan con `pairing_secret IS NULL` y el
-- código nuevo se niega a canjearlas (fail-closed, ver `status()`); 65 de esas
-- 67 además ya están vencidas. Las invitaciones en `opened` sin secreto las
-- adopta el primer lector post-deploy conservando su `user_code`, para no
-- desincronizar la pantalla del admin durante la ventana de deploy.

BEGIN;

ALTER TABLE device_invitation
  ADD COLUMN IF NOT EXISTS consumed_at    timestamptz,
  ADD COLUMN IF NOT EXISTS pairing_secret char(64);

COMMENT ON COLUMN device_invitation.consumed_at IS
  'Momento del canje. status=''consumed'' es terminal: la invitación ya entregó su único token.';
COMMENT ON COLUMN device_invitation.pairing_secret IS
  'sha256 hex del secreto de sesión de pairing (nunca el crudo). Identifica al navegador que abrió primero.';

COMMIT;
