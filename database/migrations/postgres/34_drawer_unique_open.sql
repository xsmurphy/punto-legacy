-- 34_drawer_unique_open.sql
--
-- Previene race condition en DrawerService::open(): dos requests concurrentes
-- pueden pasar el EXISTS check y crear dos filas abiertas para el mismo
-- registerId. Solución: índice único parcial que garantiza una sola fila
-- "abierta" por registerId a nivel DB.
--
-- "Abierta" = drawerCloseDate IS NULL o sentinel pre-Y2K (el legacy escribía
-- '0000-00-00' que en Postgres queda NULL tras la migración inicial).
--
-- Idempotente.

-- Postgres fold-to-lowercase: las columnas reales son `registerid` y
-- `drawerclosedate` (sin quotes). Las migraciones previas usan ese estilo.
CREATE UNIQUE INDEX IF NOT EXISTS uidx_drawer_register_open
    ON drawer (registerid)
    WHERE drawerclosedate IS NULL;
