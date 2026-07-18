-- 78_giftcard_issuance.sql: emisión de gift cards desde la venta.
--
-- La tabla `giftcard` (mig 44) solo cubría validación/consumo (canje). Esta
-- migración agrega los campos de EMISIÓN — quién la compró, para quién,
-- desde qué venta/sucursal — para unificar sobre `giftcard` y deprecar el
-- legacy `giftCardSold` (que NO se borra: sigue leíble para histórico).
--
-- Fiscal (Paraguay): la VENTA de una gift card es un ADELANTO → Recibo, no
-- Factura. El CANJE (pagar con gift card) ya emite Factura vía
-- /v1/giftcards?resource=consume — no se toca acá.

ALTER TABLE giftcard ADD COLUMN IF NOT EXISTS "beneficiaryContactId" UUID NULL;
ALTER TABLE giftcard ADD COLUMN IF NOT EXISTS "beneficiaryName" VARCHAR(255) NULL;
ALTER TABLE giftcard ADD COLUMN IF NOT EXISTS "note" TEXT NULL;
ALTER TABLE giftcard ADD COLUMN IF NOT EXISTS "issuedByTransactionId" UUID NULL;
ALTER TABLE giftcard ADD COLUMN IF NOT EXISTS "outletId" UUID NULL;
-- status: 1 = activa (default), 0 = anulada. Reservado para uso futuro
-- (hoy el estado se deriva en runtime de usedAt/expiresAt/currentBalance).
ALTER TABLE giftcard ADD COLUMN IF NOT EXISTS "status" SMALLINT NOT NULL DEFAULT 1;

-- La UNIQUE (companyId, code) ya existe desde la mig 44 (constraint de tabla).
-- Re-declarada acá como índice nombrado IF NOT EXISTS por si algún entorno
-- la perdió; coexiste sin problema con la constraint original.
CREATE UNIQUE INDEX IF NOT EXISTS uq_giftcard_company_code ON giftcard ("companyId", code);

CREATE INDEX IF NOT EXISTS idx_giftcard_beneficiary ON giftcard ("beneficiaryContactId");
CREATE INDEX IF NOT EXISTS idx_giftcard_issued_tx ON giftcard ("issuedByTransactionId");
