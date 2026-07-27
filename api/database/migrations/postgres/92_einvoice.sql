-- 92_einvoice.sql
-- Facturación electrónica — integración Automate (PY/SIFEN). F0 del plan
-- (context/28-facturacion-electronica-plan.md): cuenta del comercio +
-- infraestructura del outbox de emisión. F1 (mapper/drainer/enqueue en
-- SaleService) usa `einvoice_document`, pero la tabla se crea acá — no
-- tiene sentido partir la migración del schema en dos cuando F1 es
-- continuación directa de F0 sobre el mismo dominio.
--
-- Multi-provider desde el día 1 (`provider` default 'automate', no
-- hardcodeado en la lógica): el proveedor concreto vive detrás de
-- `EInvoiceProvider` (api/lib/EInvoice/), igual criterio que
-- `DlocalGoProvider` para pagos — si mañana se suma otro proveedor de FE,
-- el schema no cambia.
--
-- password_enc/token_enc son REVERSIBLES (cifradas, no hasheadas): la API
-- de Automate no tiene "refresh sin contraseña" (`refresh-credentials`
-- vuelve a pedirla), así que hay que poder recuperar el password en texto
-- plano para volver a loguear. Cifrado en `CredentialVault` (AES-256-GCM),
-- nunca se loguea ni se devuelve al frontend — ver api/v1/einvoice.php.
--
-- `emitter` guarda la respuesta CRUDA de `/auth/me` (razón social, RUC):
-- el spec de Automate no está tipado, así que persistir el payload tal
-- cual permite ajustar el parseo del frontend sin tener que re-consultar
-- la cuenta del comercio.
--
-- `einvoice_document` es el outbox + libro de documentos emitidos:
--   - UNIQUE(companyid, transactionid, doctype) es la idempotencia dura —
--     una venta no puede terminar facturada dos veces aunque el drainer
--     de F1 corra en paralelo sobre la misma company (ver plan §Riesgo
--     abierto: dos cajas emitiendo a la vez comparten el borrador de
--     Redis del lado de Automate, por eso F1 serializa con
--     pg_advisory_xact_lock antes de tocar esta tabla).
--   - `punto_number` es el correlativo interno de Punto (transaction.authNo)
--     guardado lado a lado con `provider_number`/`cdc`. Automate NO expone
--     campo para recibir un número externo, así que el emisor fiscal es
--     Automate: `cdc`/`provider_number` son EL documento y `punto_number`
--     queda como referencia operativa para trazabilidad (ver plan §Numeración).
--   - Índice parcial sobre next_retry_at: el drainer de F1 sólo escanea
--     pending/error, nunca los ya emitidos/cancelados/skipped — evita que
--     el índice cargue filas que nunca se van a re-consultar.
--
-- Todo lowercase sin comillas (convención del repo, migs 79/80/85/89/90).
-- IF NOT EXISTS en todo — la migración tiene que poder correr dos veces
-- sin romper (verificación obligatoria antes de deploy).

CREATE TABLE IF NOT EXISTS einvoice_account (
  companyid         UUID           PRIMARY KEY REFERENCES company(companyId) ON DELETE CASCADE,
  provider          VARCHAR(20)    NOT NULL DEFAULT 'automate',
  username          VARCHAR(190)   NOT NULL,
  password_enc      TEXT           NOT NULL,
  token_enc         TEXT,
  token_expires_at  TIMESTAMPTZ,
  status            VARCHAR(20)    NOT NULL DEFAULT 'unconfigured'
                       CHECK (status IN ('unconfigured','ok','auth_error')),
  emitter           JSONB          NOT NULL DEFAULT '{}'::jsonb,
  last_check_at     TIMESTAMPTZ,
  last_error        TEXT,
  config            JSONB          NOT NULL DEFAULT '{}'::jsonb,
  created_at        TIMESTAMPTZ    NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ    NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS einvoice_document (
  einvoicedocid     UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid         UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  transactionid     UUID           NOT NULL,
  doctype           VARCHAR(4)     NOT NULL CHECK (doctype IN ('FC','FCR','NC')),
  status            VARCHAR(12)    NOT NULL DEFAULT 'pending'
                       CHECK (status IN ('pending','sending','issued','error','cancelled','skipped')),
  attempts          SMALLINT       NOT NULL DEFAULT 0,
  next_retry_at     TIMESTAMPTZ    NOT NULL DEFAULT now(),
  cdc               VARCHAR(44),
  provider_number   VARCHAR(40),
  punto_number      VARCHAR(40),
  request_payload   JSONB,
  provider_response JSONB,
  error_message     TEXT,
  issued_at         TIMESTAMPTZ,
  cancelled_at      TIMESTAMPTZ,
  cancel_reason     TEXT,
  created_at        TIMESTAMPTZ    NOT NULL DEFAULT now(),
  updated_at        TIMESTAMPTZ    NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_einvoice_document_tx
  ON einvoice_document(companyid, transactionid, doctype);

CREATE INDEX IF NOT EXISTS idx_einvoice_document_retry
  ON einvoice_document(next_retry_at)
  WHERE status IN ('pending','error');

CREATE INDEX IF NOT EXISTS idx_einvoice_document_company_status
  ON einvoice_document(companyid, status);
