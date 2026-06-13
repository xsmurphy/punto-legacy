-- 26_register_jsonb_demote.sql
-- Degrada las columnas de CONFIG fiscal de `register` al JSONB `data`. Los
-- counters (registerInvoiceNumber, registerTicketNumber, etc.) SE QUEDAN
-- como columnas porque participan en UPDATEs atómicos en hot path del POS
-- (`UPDATE register SET registerInvoiceNumber = registerInvoiceNumber + 1`).
-- Atomizar contadores en JSONB con `jsonb_set` requiere read-modify-write
-- y pierde la garantía atómica del INT — riesgo de race en el POS.
--
-- Columnas demoted al JSONB `data` (config fiscal estática):
--   registerInvoiceAuth          — código de autorización fiscal
--   registerInvoiceAuthExpiration— vencimiento de la autorización
--   registerInvoicePrefix        — prefijo del nro. de factura
--   registerInvoiceSufix         — sufijo del nro. de factura
--   registerDocsLeadingZeros     — cantidad de ceros a la izquierda
--
-- Columnas que SE MANTIENEN (counters atómicos del POS):
--   registerInvoiceNumber, registerRemitoNumber, registerQuoteNumber,
--   registerReturnNumber, registerTicketNumber, registerOrderNumber,
--   registerPedidoNumber, registerBoletaNumber, registerScheduleNumber,
--   sessionId, lastupdated
--
-- ⚠️ DEPLOY COORDINADO con _getTableSchema() en app/panel functions.php
-- (quitar las 5 columnas del whitelist 'register'). Sin esto, los saves del
-- form de registers tiran "column does not exist".
--
-- Idempotente: jsonb_strip_nulls + IF EXISTS.

BEGIN;

-- 1. Backfill al JSONB data.
UPDATE register
SET data = COALESCE(data, '{}'::jsonb) || jsonb_strip_nulls(jsonb_build_object(
        'registerInvoiceAuth',           NULLIF(registerInvoiceAuth, 0),
        -- TIMESTAMPTZ → text en formato ISO 8601 para guardar en JSONB.
        -- Al leer (en _flattenJsonb) viene como string; el front lo parsea
        -- con Date(...) si necesita comparar.
        'registerInvoiceAuthExpiration', to_char(registerInvoiceAuthExpiration AT TIME ZONE 'UTC',
                                                  'YYYY-MM-DD"T"HH24:MI:SS"Z"'),
        'registerInvoicePrefix',         NULLIF(registerInvoicePrefix, ''),
        'registerInvoiceSufix',          NULLIF(registerInvoiceSufix,  ''),
        'registerDocsLeadingZeros',      NULLIF(registerDocsLeadingZeros, 0)
    ))
WHERE registerInvoiceAuth           IS NOT NULL
   OR registerInvoiceAuthExpiration IS NOT NULL
   OR registerInvoicePrefix         IS NOT NULL
   OR registerInvoiceSufix          IS NOT NULL
   OR registerDocsLeadingZeros      IS NOT NULL;

-- 2. Drop de las columnas demoted.
ALTER TABLE register DROP COLUMN IF EXISTS registerInvoiceAuth;
ALTER TABLE register DROP COLUMN IF EXISTS registerInvoiceAuthExpiration;
ALTER TABLE register DROP COLUMN IF EXISTS registerInvoicePrefix;
ALTER TABLE register DROP COLUMN IF EXISTS registerInvoiceSufix;
ALTER TABLE register DROP COLUMN IF EXISTS registerDocsLeadingZeros;

COMMIT;
