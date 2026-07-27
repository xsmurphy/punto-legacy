-- 94_order_fulfillment.sql
-- Fulfillment de la orden (F-D-0 + parte de F-D-1, context/27-delivery-sla-plan.md
-- PARTE B). Idempotente — corre en cada boot (patrón migs 85/87).
--
-- `fulfillment` es ORTOGONAL a `source` (§B.1): `source` dice DE DÓNDE vino
-- el pedido (mostrador, espacio, ecommerce, agenda); `fulfillment` dice CÓMO
-- llega al cliente (se sirve en el local, lo retira, se le envía). Un pedido
-- telefónico (`source=counter`) puede ser delivery, y uno de ecommerce puede
-- ser retiro en el local — meter esto en `source` obligaría a elegir entre
-- las dos dimensiones y se pierde información. Una orden con
-- `spacesessionid` es `dine_in` por construcción (el service lo fuerza,
-- igual que ya fuerza `source='table'`).
--
-- El destino (`deliveryaddress*`) se SNAPSHOTEA al crear la orden, NUNCA se
-- re-lee del contacto (§B.3, mismo principio que `targetminutes` y el costo
-- de envío): el cliente puede mudarse o borrar la dirección después, y ese
-- pedido específico fue a la dirección de ese momento. `deliveryaddressid`
-- queda como referencia a `customerAddress` (mig 87) para analítica/"misma
-- dirección que la vez pasada"; las columnas de texto/coords son la copia
-- congelada que efectivamente se usa (pin del mapa, comprobante, etc).
-- `deliverylat`/`deliverylng` pueden quedar NULL — una dirección sin pin
-- (pegada como texto, sin link de mapa) es un caso válido (§D.5).
--
-- Todo lowercase sin comillas (patrón migs 79+ de este módulo).

ALTER TABLE pos_order ADD COLUMN IF NOT EXISTS fulfillment VARCHAR(12) NOT NULL DEFAULT 'dine_in';

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'pos_order_fulfillment_check'
  ) THEN
    ALTER TABLE pos_order
      ADD CONSTRAINT pos_order_fulfillment_check
      CHECK (fulfillment IN ('dine_in', 'takeaway', 'delivery'));
  END IF;
END $$;

ALTER TABLE pos_order ADD COLUMN IF NOT EXISTS deliveryaddressid UUID;
ALTER TABLE pos_order ADD COLUMN IF NOT EXISTS deliveryaddress TEXT;
ALTER TABLE pos_order ADD COLUMN IF NOT EXISTS deliveryreference TEXT;
ALTER TABLE pos_order ADD COLUMN IF NOT EXISTS deliverylat NUMERIC(10,7);
ALTER TABLE pos_order ADD COLUMN IF NOT EXISTS deliverylng NUMERIC(10,7);

CREATE INDEX IF NOT EXISTS idx_pos_order_company_fulfillment
  ON pos_order(companyid, outletid, fulfillment, status);
