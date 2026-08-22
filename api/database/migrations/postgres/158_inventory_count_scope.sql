-- 158_inventory_count_scope.sql
-- Alcance del conteo de inventario (toma física).
--
-- PROBLEMA: `InventoryCountService::create()` snapshoteaba TODOS los ítems
-- trackeables del TENANT (`SELECT itemid FROM item WHERE companyid = ? AND
-- itemstatus = 1 AND itemtrackinventory = true`) — el `outletId` elegido en
-- el diálogo solo se usaba para resolver la cantidad esperada de cada línea.
-- Resultado: al contar la sucursal A aparecían también los ítems que solo
-- existen en B/C, todos con esperado 0, y el operador terminaba con cientos
-- de líneas de ruido (y con un ajuste a 0 latente si alguien los contaba).
--
-- El catálogo en Punto es tenant-scoped (context/25 §"Catálogo"): no hay
-- columna que diga "este ítem es de la sucursal X". La pertenencia se INFIERE
-- de que exista movimiento/saldo del ítem en esa sucursal (`stock.outletid`)
-- o en ese depósito (`tolocation.locationid`). Ese es el criterio que aplica
-- ahora `InventoryCountScope`.
--
-- Esta migración solo agrega la columna donde se PERSISTE el alcance elegido.
-- `outletid`/`locationid` ya son columnas de la tabla (mig 46) — acá se
-- guarda lo que faltaba: las categorías filtradas y si se incluyeron los
-- ítems sin presencia en la sucursal. Sin esto, un conteo finalizado no
-- podría explicar por qué contiene (o no) determinado ítem.
--
-- Forma del jsonb:
--   {
--     "categoryIds":      ["<uuid>", ...],   -- [] = todas las categorías
--     "includeZeroStock": false              -- true = ignora la presencia
--   }
--
-- Default '{}' (no '{"categoryIds":[],...}') a propósito: las filas
-- históricas NO fueron creadas con este alcance — se creaban con el bug, sin
-- filtro alguno. Un `{}` significa "alcance desconocido / anterior a la mig",
-- y el lector lo distingue de un conteo nuevo que eligió explícitamente
-- "todas las categorías" (que sí guarda las dos keys).
--
-- Casing: `inventory_count` es una de las 18 tablas que la mig 150 pasó a
-- lowercase sin comillas. La columna nueva se crea SIN comillas para no
-- reintroducir la convención mixta que esa migración eliminó.
--
-- Idempotente (IF NOT EXISTS). No destructiva: agrega una columna con
-- DEFAULT constante — en PG 11+ es metadata, no reescribe la tabla.

BEGIN;

ALTER TABLE inventory_count
  ADD COLUMN IF NOT EXISTS scope jsonb NOT NULL DEFAULT '{}'::jsonb;

COMMENT ON COLUMN inventory_count.scope IS
  'Alcance con el que se abrió el conteo: {"categoryIds":[uuid...], '
  '"includeZeroStock":bool}. categoryIds vacío = todas las categorías. '
  'includeZeroStock=false exige presencia del ítem en la sucursal '
  '(stock.outletid) o en el depósito (tolocation.locationid). '
  '{} = fila anterior a la mig 158 (alcance desconocido: se snapshoteaba '
  'todo el tenant). Ver api/lib/services/InventoryCountScope.php.';

COMMIT;
