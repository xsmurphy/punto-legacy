-- 105_purchase_draft.sql
-- OCR de facturas de compra — ver context/32-ocr-facturas-compra.md (plan
-- cerrado 2026-07-31). v1: foto → extracción IA → borrador pendiente de
-- aprobación → revisión/corrección → aprobar crea la compra real (stock +
-- finanzas) vía PurchasesService::create. La IA NUNCA escribe stock/finanzas.
--
-- Columnas físicas en MINÚSCULA SIN comillas (mismo motivo que 72_finance.sql:
-- ncmInsert/ncmUpdate arman INSERT/UPDATE con las keys del array PHP sin
-- comillas, y Postgres pliega identificadores sin comillas a minúscula).
BEGIN;

CREATE TABLE IF NOT EXISTS purchase_draft (
  draftid        uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid      uuid NOT NULL,
  outletid       uuid NOT NULL,
  userid         uuid NOT NULL,
  status         varchar(16) NOT NULL DEFAULT 'pending',  -- 'pending' | 'approved' | 'rejected'
  imageref       text,                                     -- URL pública (S3/DO Spaces)
  extracted      jsonb NOT NULL DEFAULT '{}'::jsonb,        -- JSON crudo devuelto por la IA
  edited         jsonb,                                     -- correcciones del usuario (null = sin editar aún)
  contactid      uuid,                                      -- proveedor matcheado por RUC (sugerencia corregible)
  transactionid  uuid,                                      -- compra creada al aprobar (idempotencia)
  error          text,                                      -- error de extracción, si lo hubo
  created_at     timestamptz NOT NULL DEFAULT now(),
  approved_at    timestamptz,
  CONSTRAINT purchase_draft_status_chk CHECK (status IN ('pending', 'approved', 'rejected'))
);

CREATE INDEX IF NOT EXISTS idx_purchase_draft_company_status
  ON purchase_draft (companyid, status);

COMMIT;
