-- Migración 64: unificación pantalla cliente al modelo device
--
-- La tabla customer_display se reemplaza por device (module='screen').
-- No hay datos en prod — se puede DROP sin migración de datos.
-- device.module ya existe como VARCHAR(20) sin CHECK constraint (mig 63),
-- por lo que 'screen', 'kds', 'display' son valores válidos sin ALTER adicional.

DROP TABLE IF EXISTS customer_display CASCADE;
