-- 18_document_template.sql
-- Document builder: templates configurables para tickets/facturas/cotizaciones/órdenes.
--
-- Reemplaza el approach legacy que guardaba templates como taxonomyType='printTemplate'
-- con JSON en taxonomyExtra. La tabla dedicada separa concerns y permite indexar por
-- docType + isDefault para resolver rápido qué template usar en cada documento.
--
-- config JSONB contiene los toggles de bloques visibles, textos custom de
-- header/footer, font/size/uppercase, y márgenes. Schema documentado en
-- panel-next/lib/types/document-template.ts.

CREATE TABLE IF NOT EXISTS document_template (
  templateId  UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
  companyId   UUID         NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  name        VARCHAR(120) NOT NULL,
  -- docType: receipt (ticket POS), invoice (factura), quote (presupuesto),
  -- workorder (orden de trabajo), giftcard (gift card), delivery (remito).
  docType     VARCHAR(30)  NOT NULL,
  -- pageSize: 57mm | 76mm | 80mm | A4 | A4-landscape | letter | legal
  pageSize    VARCHAR(20)  NOT NULL DEFAULT '80mm',
  -- isDefault: hay 1 default por (companyId, docType). Enforce vía partial unique index.
  isDefault   BOOLEAN      NOT NULL DEFAULT FALSE,
  config      JSONB        NOT NULL DEFAULT '{}',
  created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
  updated_at  TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_doctpl_company        ON document_template(companyId);
CREATE INDEX IF NOT EXISTS idx_doctpl_company_type   ON document_template(companyId, docType);
-- Solo un default por (companyId, docType).
CREATE UNIQUE INDEX IF NOT EXISTS idx_doctpl_default
  ON document_template(companyId, docType) WHERE isDefault = TRUE;
