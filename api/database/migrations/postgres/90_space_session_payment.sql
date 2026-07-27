-- 90_space_session_payment.sql
-- Split de cuenta — F3a+F3b+F3c (context/15-espacios-module-plan.md §F3,
-- "Plan técnico cerrado 2026-07-19").
--
-- Cobrar una mesa hoy es atómico (una transaction, markPaid de TODAS las
-- órdenes, close de la sesión). Con split hay N cobros parciales contra la
-- misma sesión — este ledger es lo que hace posible reconstruir el saldo
-- (total de la sesión − Σ pagos) sin volver a sumar desde cero cada vez, y
-- lo que deja rastro de CADA comprobante parcial (fiscal: cada pago parcial
-- es su propia `transaction`, SpaceSettlementService no crea facturación
-- nueva, reusa SaleService vía el caller).
--
-- kind:
--   'items'  — el operador seleccionó ítems puntuales; el amount se calcula
--              SIEMPRE server-side (nunca del request) y los ítems quedan
--              marcados vía CAS (pos_order_item.settledpaymentid) — el único
--              mecanismo que hace IMPOSIBLE cobrar el mismo ítem dos veces
--              (marcar un ítem ya marcado no afecta filas → aborta la TX).
--   'amount' — monto libre (adelanto), sin ítems asociados.
--   'share'  — partes iguales; sharecount registra el N usado, la última
--              parte absorbe el resto del redondeo (ver SpaceSettlementService).
--
-- outletid denormalizado (mismo criterio que pos_order_event, mig 85):
-- reportes por outlet sin JOIN a space_session.
--
-- Todo lowercase sin comillas (patrón migs 79/80/85/89).

CREATE TABLE IF NOT EXISTS space_session_payment (
  paymentid      UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
  companyid      UUID           NOT NULL REFERENCES company(companyId) ON DELETE CASCADE,
  outletid       UUID           NOT NULL,
  sessionid      UUID           NOT NULL REFERENCES space_session(sessionid) ON DELETE CASCADE,
  transactionid  UUID,
  amount         NUMERIC(14,2)  NOT NULL,
  kind           VARCHAR(8)     NOT NULL CHECK (kind IN ('items','amount','share')),
  sharecount     SMALLINT,
  created_at     TIMESTAMPTZ    NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_space_session_payment_session ON space_session_payment(companyid, sessionid);

-- Qué pago se llevó cada ítem (kind='items' exclusivamente). NULL = ítem sin
-- cobrar todavía. Sin FK a space_session_payment a propósito — mismo criterio
-- que pos_order.saletransactionid (mig 79): puntero suelto, no acopla el
-- ciclo de vida del ítem al del pago.
ALTER TABLE pos_order_item
  ADD COLUMN IF NOT EXISTS settledpaymentid UUID;

CREATE INDEX IF NOT EXISTS idx_pos_order_item_settledpayment ON pos_order_item(settledpaymentid);
