-- 100_vouchers.sql — Módulo de Vouchers F1 (context/36-vouchers-plan.md).
--
-- Un voucher es un vale por PRODUCTOS exactos (no por monto), de UN SOLO USO,
-- vendido en la caja. Al canjearlo sus ítems entran al carrito con el precio
-- congelado de la emisión y NO suman al total (ya se cobró al emitirse).
-- Dominio aparte de `giftcard` (esa es por importe) — no lo reemplaza.
--
--   - voucher: cabecera. `code` único por company (mismo criterio de
--     generación que giftcard). `issuedbytransactionid` es la venta que lo
--     emitió (auditable); `usedat`/`usedbytransactionid` marcan el canje,
--     igual que `giftcard`. `status` reservado para anulación manual (1activo/0anulado),
--     igual convención que giftcard mig 78.
--   - voucher_item: contenido — ítems + qty comprometida + precio unitario
--     CONGELADO al emitir (`unitpriceatissue`). El total del vale es
--     Σ(qty × unitpriceatissue), no se duplica en la cabecera (igual razón
--     por la que pos_order no guarda total).
--
-- Todo lowercase sin comillas (mig 71/72 doc de convención — mismo estilo que
-- pos_order/pos_order_item, mig 79).

CREATE TABLE IF NOT EXISTS voucher (
  voucherid              UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid              UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid               UUID           NOT NULL REFERENCES outlet(outletid),
  code                   VARCHAR(64)    NOT NULL,
  status                 SMALLINT       NOT NULL DEFAULT 1,
  expiresat              TIMESTAMPTZ,
  usedat                 TIMESTAMPTZ,
  usedbytransactionid    UUID           REFERENCES transaction(transactionId),
  issuedbytransactionid  UUID           NOT NULL REFERENCES transaction(transactionId),
  beneficiarycontactid   UUID           REFERENCES contact(contactid),
  created_at             TIMESTAMPTZ    NOT NULL DEFAULT now()
);

-- Único por company + índice para el lookup por código — la query caliente
-- del canje (validate/consume filtran por companyid + code). La constraint
-- única sirve de índice: no se duplica uno aparte.
CREATE UNIQUE INDEX IF NOT EXISTS uq_voucher_company_code ON voucher(companyid, code);

CREATE INDEX IF NOT EXISTS idx_voucher_issued_tx ON voucher(issuedbytransactionid);
CREATE INDEX IF NOT EXISTS idx_voucher_used_tx ON voucher(usedbytransactionid);
CREATE INDEX IF NOT EXISTS idx_voucher_beneficiary ON voucher(beneficiarycontactid);

CREATE TABLE IF NOT EXISTS voucher_item (
  voucheritemid       UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  voucherid           UUID           NOT NULL REFERENCES voucher(voucherid) ON DELETE CASCADE,
  companyid           UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  itemid              UUID           NOT NULL REFERENCES item(itemid) ON DELETE RESTRICT,
  qty                 NUMERIC(15,3)  NOT NULL,
  unitpriceatissue    NUMERIC(14,2)  NOT NULL,
  created_at          TIMESTAMPTZ    NOT NULL DEFAULT now(),
  CONSTRAINT voucher_item_qty_positive CHECK (qty > 0)
);

CREATE INDEX IF NOT EXISTS idx_voucher_item_voucher ON voucher_item(voucherid);
CREATE INDEX IF NOT EXISTS idx_voucher_item_company ON voucher_item(companyid);
