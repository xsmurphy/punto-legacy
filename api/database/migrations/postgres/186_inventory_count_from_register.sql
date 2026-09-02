-- 186_inventory_count_from_register.sql
-- El conteo de stock hecho DESDE LA CAJA (F1 de context/63).
--
-- Tres columnas, dos propósitos distintos.
--
-- ── `opid` — identidad de la operación, y con ella la idempotencia ──────────
--
-- La caja cuenta sin red y encola la operación (`context/51` §2). El caso a
-- sobrevivir no es el rechazo sino el silencioso: la request LLEGÓ y se
-- aplicó, y la respuesta se perdió; el device no lo distingue de "no llegó" y
-- reintenta. Todas las operaciones que la cola transporta hoy toleran eso
-- porque son idempotentes por naturaleza (asignaciones de valor, dedupe por
-- monto+fecha, `ON CONFLICT DO NOTHING` con id del cliente).
--
-- Un conteo NO lo es: crear la sesión inserta una fila nueva y consume un
-- correlativo de documento, y finalizarla mueve el ledger. Dos envíos serían
-- dos conteos y DOS ajustes de stock sobre el mismo recuento — el inventario
-- quedaría con el doble de la diferencia.
--
-- La salida es la misma que ya usó `printer_binding` (context/51 §3): la
-- identidad la genera el cliente y el servidor la hace única. Acá esa
-- identidad vive en la fila que la operación crea —no en una tabla de
-- "operaciones ya vistas"— así que un reenvío encuentra su propio conteo y
-- devuelve el mismo resultado en vez de fabricar un segundo.
--
-- Índice único PARCIAL: los conteos creados desde el PANEL no tienen `opid`
-- (no vienen de una cola) y son la enorme mayoría; un único total sobre NULL
-- no los limita en Postgres, pero el parcial además no los indexa.
-- Scopeado por `companyid`: un `opid` es único dentro de un comercio, y dos
-- tenants no pueden colisionar entre sí ni descubrirse por el error.
--
-- ── `registerid` / `drawerid` — el turno como CONTEXTO ──────────────────────
--
-- El owner fue explícito (context/63 §"El modelo"): los conteos son eventos
-- independientes, ninguno depende de otro ni del turno. Estas dos columnas
-- anotan en qué caja y en qué turno ocurrió el conteo para poder mirarlo
-- después — no lo condicionan, no lo encadenan y NUNCA son obligatorias. Un
-- conteo con la caja cerrada es válido y las deja en NULL.
--
-- No van dentro de `scope` (mig 158) a propósito: ese jsonb responde "qué
-- artículos entraron en esta sesión". Dónde se hizo es otra pregunta.
--
-- Casing: `inventory_count` es una de las tablas que la mig 150 pasó a
-- lowercase sin comillas. Las columnas nuevas se crean SIN comillas.
--
-- Idempotente (IF NOT EXISTS). No destructiva: columnas nullable sin default,
-- metadata pura, no reescribe la tabla.

BEGIN;

ALTER TABLE inventory_count
  ADD COLUMN IF NOT EXISTS opid       text NULL,
  ADD COLUMN IF NOT EXISTS registerid uuid NULL,
  ADD COLUMN IF NOT EXISTS drawerid   uuid NULL;

CREATE UNIQUE INDEX IF NOT EXISTS uidx_inventory_count_company_opid
  ON inventory_count (companyid, opid)
  WHERE opid IS NOT NULL;

COMMENT ON COLUMN inventory_count.opid IS
  'Identidad de la operación encolada que creó este conteo (cliente-generada, '
  'header X-Punto-Op-Id). Única por comercio: un reenvío tras un timeout '
  'encuentra esta misma fila en vez de crear un segundo conteo y un segundo '
  'ajuste de stock. NULL = conteo creado desde el panel, sin cola de por medio. '
  'Ver api/lib/services/InventoryCountService.php::submitFromRegister().';

COMMENT ON COLUMN inventory_count.registerid IS
  'Caja en la que se hizo el conteo. CONTEXTO, no dependencia: el conteo no '
  'requiere caja ni turno abierto (context/63). NULL desde el panel.';

COMMENT ON COLUMN inventory_count.drawerid IS
  'Turno vigente al momento del conteo, si había uno. CONTEXTO: los conteos '
  'son independientes entre sí y del turno — esta columna sirve para mirar '
  'después en qué turno ocurrió, nunca para encadenarlos.';

COMMIT;
