-- 95_einvoice_factomate.sql
-- Facturación electrónica — PIVOT de proveedor: Automate → Factomate.
--
-- Por qué: la F0 (mig 92) se implementó contra la API de Automate
-- (`app.automate.com.py`) asumiendo que era el motor real de facturación
-- electrónica. No lo es: Automate es OTRO cliente de Factomate, igual que
-- Punto va a serlo. El motor real (SIFEN/timbrado/firma) es Factomate
-- (a.k.a. efatech, `https://factomatedev.tech-precision.com` en test). Ver
-- `context/28-facturacion-electronica-plan.md` (reescrito en este pivot) y
-- la guía de integración real en
-- `/Users/xstian/Dropbox/Automate/Agent/context/09-integracion-factomate.md`.
--
-- CORRECTIVA, no se edita la 92 — ya está pusheada a main y puede haber
-- corrido en prod. Todo acá es `ADD COLUMN IF NOT EXISTS` / `ALTER COLUMN`
-- idempotente al 100%, la migración tiene que poder correr dos veces sin
-- romper (verificación obligatoria antes de deploy, mismo criterio que 92).
--
-- Qué cambia y por qué:
--
--   - `einvoice_account.phone_enc` — el teléfono del titular. Factomate lo
--     exige como header `phonenumber` en TODAS las llamadas autenticadas
--     (sin él, `/api/sincro/config` tira "The given header was not found")
--     y además es el segundo factor de `POST /api/account/PhoneLogin` (el
--     primero es usuario+contraseña contra `/Token`). Por eso se cifra
--     igual que la contraseña — es tan "credencial" como ella, aunque el
--     backend lo devuelve en claro al operador autenticado para poder
--     editarlo sin re-tipearlo (ver EInvoiceService::getAccount — el
--     cifrado protege un dump/backup de la BD, no al dueño de la cuenta).
--
--   - `einvoice_account.environment` — Factomate tiene HOSTS DISTINTOS para
--     test y prod (a diferencia de Automate, que no distinguía esto en el
--     schema anterior). Sin esta columna no hay forma de saber contra qué
--     host autenticar, y el fallback equivocado (mandar prod a test, o
--     viceversa) es exactamente el tipo de bug silencioso que revienta
--     tarde — por eso el CHECK explícito y el default 'test' (nunca prod
--     por accidente).
--
--   - `einvoice_account.stamp` / `stamp_synced_at` — el timbrado vigente
--     (establecimiento, punto de expedición, vigencia), cacheado de
--     `POST /api/sincro/config` (`stamps[0]`). Factomate no expone forma de
--     CREAR un timbrado por API — se lee, no se crea (se provisiona del
--     lado de Factomate antes de conectar la cuenta) — así que esta
--     columna es una caché de lectura, no un dato editable por Punto.
--
--   - `einvoice_document.document_number` — el `DocumentNumber` que
--     devuelve `POST /api/electronicDocument/Bulk` (F1). Se agrega como
--     columna nueva y explícita en vez de reusar `provider_number` (que
--     mig 92 dejó como nombre genérico "número asignado por el proveedor"
--     pensando en Automate) para que el nombre documente exactamente qué
--     campo de la respuesta de Factomate representa. `provider_number`
--     queda sin uso — F1 decide si se consolida o se retira, no se toca
--     acá (ninguna fila de `einvoice_document` existe todavía: F0 nunca
--     escribió esta tabla).
--
--   - `einvoice_document.sifen_status` / `sifen_result` / `sifen_checked_at`
--     — SIFEN puede RECHAZAR un DE minutos después de que `/Bulk` ya
--     devolvió un CDC válido (ej. código 1002, duplicado) y recién ahí
--     poblar el resultado real. Un CDC devuelto por `/Bulk` NO garantiza
--     aceptación definitiva — es solo "Factomate lo firmó y lo mandó a
--     SIFEN". F1/F2 tienen que reconciliar contra
--     `GET /api/ElectronicDocument/GetAll` (o `getBulk/{id}`) para saber
--     el estado real; estas columnas son donde esa reconciliación
--     persiste el resultado. `status` (mig 92: pending/sending/issued/...)
--     sigue siendo el estado del OUTBOX de Punto (¿se mandó?); `sifen_status`
--     es el estado FISCAL real (¿SIFEN lo aceptó?) — son dos cosas
--     distintas que se popularizan en momentos distintos.
--
--   - `provider` default → 'factomate' + backfill de filas 'automate' →
--     'factomate'. La columna ya era multi-provider desde el día 1 (mig 92
--     §comentario), así que no cambia el diseño, solo el valor por default.
--
-- Todo lowercase sin comillas (convención del repo). IF NOT EXISTS en todo.

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS phone_enc TEXT;

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS environment VARCHAR(8) NOT NULL DEFAULT 'test'
    CHECK (environment IN ('test', 'prod'));

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS stamp JSONB NOT NULL DEFAULT '{}'::jsonb;

ALTER TABLE einvoice_account
  ADD COLUMN IF NOT EXISTS stamp_synced_at TIMESTAMPTZ;

ALTER TABLE einvoice_account
  ALTER COLUMN provider SET DEFAULT 'factomate';

UPDATE einvoice_account SET provider = 'factomate' WHERE provider = 'automate';

ALTER TABLE einvoice_document
  ADD COLUMN IF NOT EXISTS document_number VARCHAR(40);

ALTER TABLE einvoice_document
  ADD COLUMN IF NOT EXISTS sifen_status VARCHAR(20);

ALTER TABLE einvoice_document
  ADD COLUMN IF NOT EXISTS sifen_result JSONB;

ALTER TABLE einvoice_document
  ADD COLUMN IF NOT EXISTS sifen_checked_at TIMESTAMPTZ;
