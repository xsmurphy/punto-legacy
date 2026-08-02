BEGIN;

-- ============================================================
-- F5 del roadmap admin (context/34-admin-saas-plan.md) — facturación
-- "dogfooding": la suscripción SaaS se emite como una VENTA REAL dentro de
-- un tenant Punto propio (el "emisor", configurado en admin/platform).
--
-- Columnas físicas en MINÚSCULA SIN comillas (mismo criterio que 108_tenant_health.sql).
-- ============================================================

-- company.isinternal — excluye al tenant emisor de las métricas cross-tenant
-- (AdminReportsService F1, TenantHealthService::computeAll F2). El listado de
-- companies lo sigue mostrando (con badge "Interno" en el frontend).
ALTER TABLE company
  ADD COLUMN IF NOT EXISTS isinternal smallint NOT NULL DEFAULT 0;

-- saas_invoice_sale — 1 fila por billing_invoice facturada dentro del tenant
-- emisor. La PK (invoiceid) ES la idempotencia: nunca puede haber dos ventas
-- para la misma invoice, sin importar cuántas veces reintente el webhook o
-- se apriete el botón manual "Emitir factura Punto".
--
--   invoiceid     → billing_invoice.id (la invoice del tenant CLIENTE, pagada)
--   companyid     → el tenant CLIENTE facturado (NO el emisor)
--   transactionid → la venta real creada en `transaction`, dentro del tenant emisor
CREATE TABLE IF NOT EXISTS saas_invoice_sale (
  invoiceid     uuid PRIMARY KEY REFERENCES billing_invoice(id),
  companyid     uuid NOT NULL REFERENCES company(companyId),
  transactionid uuid NOT NULL REFERENCES transaction(transactionId),
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_saas_invoice_sale_company ON saas_invoice_sale (companyid, created_at DESC);

COMMIT;
