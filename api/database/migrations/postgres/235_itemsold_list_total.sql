-- 235_itemsold_list_total.sql — Wallet: valor de LISTA congelado en la línea
-- del consumo con saldo (context/74 §12.5 → §13, reporte de bolsillos).
--
-- El problema que cierra: el importe de un consumo con saldo (tipo 15) lo
-- manda la caja, con la misma confianza que una venta normal. Una venta normal
-- queda expuesta en el arqueo y en el margen; un consumo NO entra a ninguno de
-- los dos por diseño (D12/D13). Para poder mostrar "este consumo se cobró por
-- menos de lo que valía" hace falta saber cuánto valía, y eso NO se puede
-- derivar de lo que ya se guarda:
--
--   - `itemsoldtotal` es el bruto que eligió la CAJA (precio × cantidad), no
--     el del catálogo — si la caja bajó el precio, la línea ya viene baja;
--   - `transaction.transactiontotal` es el `subtotal` del payload, ni siquiera
--     la suma de las líneas (ver el docblock de `expandAddonSelections`);
--   - el precio de lista de HOY no sirve para un consumo de ayer: el catálogo y
--     las listas de precio cambian.
--
-- Así que se congela al guardar, como el IVA y el COGS: `SaleService` resuelve
-- el precio de lista EN EL SERVIDOR (lista del cliente → lista de la sucursal
-- → precio del ítem, `PriceListService`) y escribe acá unidades × precio de
-- lista, redondeado a los decimales del comercio. Mismo grano que
-- `itemsoldtotal`, así que la diferencia de una línea es una resta.
--
-- Hacia adelante: los consumos anteriores quedan en NULL ("no se sabe") y el
-- reporte no los evalúa. Inventar el precio de lista de un consumo pasado con
-- el catálogo actual daría diferencias falsas.
--
-- Hoy solo lo escriben los consumos con saldo (tipo 15). La columna no es de
-- la wallet: es "valor de lista de la línea", y cualquier otro documento que
-- necesite el mismo control lo puede empezar a escribir sin otra migración.
--
-- Columna real y no una clave en `meta`: el reporte la SUMA por consumo.
-- Nullable y sin default → en una tabla particionada es un cambio de catálogo,
-- no reescribe filas.

BEGIN;

SET LOCAL lock_timeout = '10s';

ALTER TABLE itemsold
  ADD COLUMN IF NOT EXISTS itemsoldlisttotal numeric(15,2);

COMMENT ON COLUMN itemsold.itemsoldlisttotal IS
  'Valor de LISTA de la línea (unidades × precio de lista resuelto en el '
  'servidor al guardar: lista del cliente, de la sucursal o precio del ítem), '
  'congelado. Hoy solo en consumos con saldo (tipo 15, context/74). NULL = no '
  'se congeló (documentos anteriores o de otro tipo).';

COMMIT;
