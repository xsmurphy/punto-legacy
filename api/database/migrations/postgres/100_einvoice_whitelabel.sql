-- 100_einvoice_whitelabel.sql
-- Facturación electrónica — F7: onboarding white-label
-- (context/28-facturacion-electronica-plan.md §Onboarding del emisor).
--
-- El modelo cambia de "el comercio tipea su credencial de Factomate" (F0,
-- andamio para probar F1 contra una cuenta provisionada a mano) a "Punto
-- provisiona el emisor por API con su credencial admin y el comercio nunca
-- sabe que Factomate existe". La credencial admin es un secreto GLOBAL de
-- env (FACTOMATE_ADMIN_*), nunca de BD — acá solo se agregan los
-- identificadores que devuelve el alta y el espejo del formulario fiscal.
--
--   - `factomate_tenant_id` — `Id` que devuelve POST /api/Tenant/CreateExternal.
--     Es la llave de PUT /api/Tenant, POST /api/Tenant/{id}/UploadCert y el
--     ABM de hijas. INT porque la API de Factomate usa ids enteros para
--     Tenant (manual ABM §2.4: "Id": 42).
--   - `factomate_user_id` — `UserId` del admin del tenant creado (GUID de
--     ASP.NET Identity). Se guarda para poder pedir reseteo de contraseña
--     o soporte sin tener que buscarlo del lado de ellos.
--   - `fiscal` — espejo del formulario legal que llenó el comercio
--     (tipo de contribuyente, actividad económica, timbrado pedido, CSC id).
--     Es lo que la UI re-muestra sin depender de un GET a Factomate, y lo
--     que permite reanudar un provisioning que falló a mitad de camino.
--     El secreto del CSC y el certificado NO viven acá (pasan, no se
--     guardan — regla del plan §Certificado).
--   - `provisioning` — checkpoint por paso del alta compuesta
--     ({tenantCreated, fiscalApplied, activityId, stampId, certUploaded}).
--     CreateExternal NO es idempotente del lado de Factomate (§2.6:
--     compensación manual, sin transacción) — sin checkpoint, un reintento
--     a ciegas tras un timeout puede crear el emisor dos veces.
--
-- phone_enc (mig 95) pasa a guardar la IDENTIDAD DE LOGIN del usuario del
-- tenant — verificado contra la API real (2026-07-30) que el header
-- `phonenumber` es el UserName del usuario, no un teléfono literal. Para
-- usuarios provisionados por CreateExternal, UserName = Email. No se
-- renombra la columna: el rename rompería el código en caliente durante el
-- deploy y el nombre queda documentado acá y en EInvoiceService.
--
-- Todo lowercase sin comillas. IF NOT EXISTS en todo — tiene que poder
-- correr dos veces sin romper (verificación obligatoria antes de deploy).

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS factomate_tenant_id INTEGER;

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS factomate_user_id VARCHAR(64);

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS fiscal JSONB NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS provisioning JSONB NOT NULL DEFAULT '{}'::jsonb;

-- El alta ya no la hace el operador con credenciales: username puede no
-- existir hasta que CreateExternal responda. Se relaja el NOT NULL de mig 92
-- (la fila se crea ANTES de llamar a Factomate, como checkpoint).
ALTER TABLE einvoice_account
  ALTER COLUMN username DROP NOT NULL;

ALTER TABLE einvoice_account
  ALTER COLUMN password_enc DROP NOT NULL;

-- Estado nuevo del ciclo de vida: cuenta creada localmente pero el
-- provisioning contra Factomate no terminó (reanudable).
ALTER TABLE einvoice_account
  DROP CONSTRAINT IF EXISTS einvoice_account_status_check;

ALTER TABLE einvoice_account
  ADD CONSTRAINT einvoice_account_status_check
  CHECK (status IN ('unconfigured','provisioning','ok','auth_error'));
