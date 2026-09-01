-- Índice para responder "¿este dispositivo tocó alguna orden?" sin escanear
-- la actividad entera del comercio.
--
-- POR QUÉ. `DELETE /v1/devices?hard=1` dejó de borrar dispositivos con
-- historial operativo (context/29, `DeviceHistoryService`): borrarlos deja
-- filas huérfanas apuntando a un aparato que ya no existe — con FK dura y
-- error 23503 en el caso de `register_lease`, y EN SILENCIO en las otras tres
-- tablas que llevan `deviceId` sin FK. Una de esas tres es `pos_order_event`,
-- la auditoría de quién movió cada orden entre estados.
--
-- El chequeo corre en dos lugares: el gate del DELETE (una fila) y el listado
-- `GET /v1/devices`, que lo evalúa por CADA dispositivo del parque. Los otros
-- tres EXISTS ya caen sobre un índice existente
-- (`idx_register_lease_device`, `idx_auth_session_device`,
-- `idx_station_printer_device`); `pos_order_event` no tenía ninguno por actor
-- — sus tres índices son por orden, por outlet y por estación (mig 85). Sin
-- este, un dispositivo SIN eventos obliga a recorrer todos los eventos del
-- tenant para poder afirmar que no hay ninguno, y son la tabla de más alta
-- frecuencia del dominio de órdenes.
--
-- POR QUÉ PARCIAL. `actor_id` guarda un `userid` O un `deviceid` según
-- `actor_kind`; el predicado que nos interesa siempre lleva
-- `actor_kind = 'device'`, así que el índice cubre exactamente esas filas y no
-- paga por las de usuario, que son la mayoría.
--
-- `IF NOT EXISTS` — idempotente, correrla dos veces no falla. Sin
-- CONCURRENTLY a propósito: las migraciones de este proyecto corren al
-- arranque del contenedor del backend, dentro de la transacción del runner, y
-- CONCURRENTLY no puede correr ahí.

CREATE INDEX IF NOT EXISTS idx_pos_order_event_actor_device
  ON pos_order_event (companyid, actor_id)
  WHERE actor_kind = 'device';
