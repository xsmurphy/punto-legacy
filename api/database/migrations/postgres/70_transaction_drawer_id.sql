BEGIN;

-- Asocia cada transacción a la SESIÓN de caja (drawer) en la que se registró.
-- Permite que el resumen de Control de Caja sea EXACTO y nunca pierda una
-- transacción por un borde de fecha (el filtro por drawerOpenDate era frágil).
--
-- NULL permitido: filas viejas (pre-migración) y ventas registradas sin caja
-- abierta (controlCaja off). El resumen las recupera vía fallback por fecha.
--
-- Sin FK constraint duro a propósito: si la fila de `drawer` se borra, el insert
-- de la venta no debe romper. El índice alcanza para el filtro del resumen.
ALTER TABLE transaction ADD COLUMN IF NOT EXISTS "drawerId" uuid;

CREATE INDEX IF NOT EXISTS idx_transaction_drawerid
    ON transaction ("drawerId") WHERE "drawerId" IS NOT NULL;

COMMIT;
