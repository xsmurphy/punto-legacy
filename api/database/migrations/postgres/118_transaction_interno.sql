-- 118_transaction_interno.sql
-- Deja registrado que una venta es de consumo interno.
--
-- El POS tiene el botón "Interno" y el carrito manda `interno: true` en el
-- payload (frontend/lib/commands/create-sale.ts), pero el flag moría en el
-- borde de la API: `SaleInput` no tiene ese campo, así que el backend lo
-- descartaba en silencio. Una venta marcada como interna quedaba registrada
-- igual que una venta común y ningún reporte podía distinguirla.
--
-- Es exactamente el patrón que mig 101 arregló para `ivaRemoved`, y que ese
-- mismo archivo ya señalaba como pendiente: "mismo patrón que el flag
-- `interno`, que también muere en el borde de la API".
--
-- Columna real y no una clave en el JSONB `meta` por el mismo motivo que
-- ivaRemoved: los reportes tienen que poder filtrar por esto.

BEGIN;

ALTER TABLE transaction
  ADD COLUMN IF NOT EXISTS interno BOOLEAN NOT NULL DEFAULT false;

COMMENT ON COLUMN transaction.interno IS
  'true = venta de consumo interno (botón Interno del POS), no una venta a cliente.';

COMMIT;
