-- 101_transaction_iva_removed.sql
-- Deja registrado que una venta se emitió SIN IVA.
--
-- El POS tiene un toggle "quitar IVA" que divide los precios por 1,10 — el
-- cajero ve y cobra el precio sin IVA. Pero ese flag moría en el browser: el
-- payload mandaba los precios CON IVA y no había dónde asentar que la venta fue
-- sin impuesto, así que la transacción quedaba registrada por MÁS plata de la
-- que entró y ningún reporte podía distinguirla (bug 2026-07-30, mismo patrón
-- que el flag `interno`, que también muere en el borde de la API).
--
-- Desde ahora el front manda los importes efectivamente cobrados y esta columna
-- dice por qué son esos y no los de lista. Los reportes que derivan el IVA del
-- `taxId` del ítem deben respetarla: si es true, esa venta no tiene IVA que
-- devengar.
BEGIN;

ALTER TABLE transaction
  ADD COLUMN IF NOT EXISTS ivaRemoved BOOLEAN NOT NULL DEFAULT false;

COMMENT ON COLUMN transaction.ivaRemoved IS
  'true = venta emitida sin IVA (toggle del POS). Los importes ya vienen netos.';

COMMIT;
