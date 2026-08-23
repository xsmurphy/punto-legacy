-- 163_space_session_alias_and_merge.sql
-- Alias libre de la sesión de espacio + linaje de fusión de cuentas
-- (context/15-espacios-module-plan.md, pedidos del owner 2026-08-23).
--
-- ============================================================
-- 1. `alias` — el nombre que el mozo le pone a la MESA ABIERTA
-- ============================================================
-- NO confundir con `space.name`: ese es el nombre FIJO de la mesa física
-- ("Mesa 1", "Barra 3"), lo configura el encargado desde Ajustes y sobrevive a
-- todas las ocupaciones. El alias es de la OCUPACIÓN: "los del cumpleaños",
-- "la mesa del flaco de camisa". Nace al abrir la mesa, sirve para que el mozo
-- la reconozca de un vistazo, y muere con ella — la sesión siguiente sobre el
-- mismo espacio arranca sin alias.
--
-- Por eso vive en `space_session` y no en `space`, y por eso es una columna
-- propia y no `note`: `note` ya existe en esta tabla como observación
-- operativa de la sesión (present() la expone desde la mig 80) y meter dos
-- semánticas en el mismo campo obliga a todos los lectores a adivinar cuál de
-- las dos está viendo.
ALTER TABLE space_session
  ADD COLUMN IF NOT EXISTS alias VARCHAR(60);

COMMENT ON COLUMN space_session.alias IS
  'Nombre libre que el mozo le da a ESTA ocupación ("los del cumpleaños"). '
  'Efímero: pertenece a la sesión, no al espacio (space.name es el nombre '
  'fijo de la mesa). Se pierde al cerrar la mesa, por diseño.';

-- ============================================================
-- 2. `mergedinto` — a qué cuenta se absorbió esta sesión
-- ============================================================
-- Unir dos mesas mueve las órdenes y los pagos parciales de la sesión ORIGEN a
-- la sesión DESTINO, y deja la origen cerrada. Sin esta columna esa sesión
-- queda indistinguible de una cerrada por cobro: `saletransactionid` NULL,
-- `closed_at` seteado y cero órdenes colgando. Un arqueo que la encuentre así
-- no puede saber si se fusionó o si alguien cerró una mesa vacía.
--
-- No se agrega un status 'merged' al CHECK a propósito: `closed` es
-- exactamente lo que la sesión origen es (terminada, ya no ocupa el espacio, y
-- el índice único parcial `uq_space_session_active_per_space` la deja de
-- contar). El motivo del cierre es un dato aparte, y este es el dato.
ALTER TABLE space_session
  ADD COLUMN IF NOT EXISTS mergedinto UUID;

COMMENT ON COLUMN space_session.mergedinto IS
  'Sesión que absorbió a esta al unir cuentas. NOT NULL solo en la sesión '
  'ORIGEN de una fusión, que queda status=closed sin saletransactionid: es lo '
  'único que distingue "se unió a otra mesa" de "se cerró vacía".';

-- Sin FK a space_session(sessionid): la sesión destino puede archivarse o
-- purgarse por retención (context/48) antes que la origen, y una FK
-- convertiría eso en un borrado en cascada o en un bloqueo. El valor es
-- trazabilidad, no integridad referencial dura.

-- Parcial: solo interesa recorrer las sesiones fusionadas (un puñado), no las
-- millones de sesiones normales que tienen la columna en NULL.
CREATE INDEX IF NOT EXISTS idx_space_session_mergedinto
  ON space_session (mergedinto)
  WHERE mergedinto IS NOT NULL;
