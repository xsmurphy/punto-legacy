-- =============================================================================
-- 170_item_outlet.sql — un ítem vive en N sucursales (mínimo UNA, siempre).
--
-- Regla del owner (textual): "La multi-sucursal es para ítems y usuarios. Un
-- ítem NUNCA puede no tener una sucursal asignada, eso es regla básica. Un
-- producto tiene que estar en algún lugar de la empresa, de lo contrario no
-- hay trazabilidad y no tiene sentido que un producto aparezca de la nada."
--
-- Reemplaza el modelo 1:1 `item.outletid` (UUID = exclusivo de esa sucursal,
-- NULL = "visible en todas"). El estado "cero sucursales" pasa a ser
-- INVÁLIDO — a diferencia de `contact_outlet` (mig 66), donde cero filas
-- significa "todas las sucursales". NO copiar esa semántica acá.
--
-- Patrón de tabla: `66_contact_outlets.sql` (mismo shape, mismos índices,
-- misma decisión de NO dropear la columna legacy).
--
-- ⚠ NO RE-EJECUTAR A MANO. El DDL es idempotente (IF NOT EXISTS / ON CONFLICT
-- DO NOTHING / DROP TRIGGER IF EXISTS), pero el BACKFILL **no lo es**, y la
-- apariencia de idempotencia es justamente la trampa:
--
--   El paso (b) reparte "todas las sucursales del tenant" a los ítems con
--   `item.outletid IS NULL`. ANTES de esta migración eso significaba "ítem
--   visible en todas". DESPUÉS, la columna queda CONGELADA (nadie la escribe)
--   y por lo tanto TODO ítem nuevo la tiene en NULL — sin que eso signifique
--   nada sobre su visibilidad. Un re-run abriría cada ítem creado después de
--   la migración a TODAS las sucursales, en silencio.
--
-- El ledger `schema_migrations` ya evita el re-run en el flujo normal
-- (`migrate.php` la aplica una sola vez). Esta nota es para el caso manual:
-- si hay que reparar datos, escribí un backfill nuevo con su propio criterio,
-- no vuelvas a correr este archivo.
-- =============================================================================

BEGIN;

-- ── 1. Tabla ────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS item_outlet (
  itemid    UUID NOT NULL REFERENCES item(itemid)       ON DELETE CASCADE,
  outletid  UUID NOT NULL REFERENCES outlet(outletid)   ON DELETE CASCADE,
  companyid UUID NOT NULL REFERENCES company(companyid) ON DELETE CASCADE,
  PRIMARY KEY (itemid, outletid)
);

-- La PK (itemid, outletid) ya cubre el `EXISTS (... WHERE itemid = i.itemId
-- AND outletid = ?)` de `outletVisibilityClause()`. Los dos índices de abajo
-- cubren el camino inverso (todos los ítems de una sucursal) y el wipe de
-- tenant, igual que en `contact_outlet`.
CREATE INDEX IF NOT EXISTS idx_item_outlet_outlet  ON item_outlet(outletid);
CREATE INDEX IF NOT EXISTS idx_item_outlet_company ON item_outlet(companyid);

-- ── 2. Backfill ─────────────────────────────────────────────────────────────
-- (a) Ítem con sucursal explícita → esa sucursal, tal cual.
--     El EXISTS descarta punteros huérfanos (outletid de otro tenant o de una
--     sucursal ya borrada): la FK nueva los rechazaría y tiraría la migración.
INSERT INTO item_outlet (itemid, outletid, companyid)
  SELECT i.itemid, i.outletid, i.companyid
    FROM item i
   WHERE i.outletid IS NOT NULL
     AND EXISTS (
           SELECT 1 FROM outlet o
            WHERE o.outletid  = i.outletid
              AND o.companyid = i.companyid
         )
ON CONFLICT DO NOTHING;

-- (b) Ítem con outletid NULL — hoy significa "visible en TODAS las sucursales"
--     → una fila por CADA sucursal del tenant. Preserva la visibilidad actual
--     exacta y de paso cumple el mínimo-una.
--
--     Se incluyen las sucursales inactivas (outletstatus = 0) A PROPÓSITO: la
--     semántica vieja de NULL no miraba el estado de la sucursal, y filtrar
--     acá cambiaría la visibilidad de un ítem al reactivarse una sucursal.
--     Preservar el comportamiento > limpiar datos en una migración.
INSERT INTO item_outlet (itemid, outletid, companyid)
  SELECT i.itemid, o.outletid, i.companyid
    FROM item i
    JOIN outlet o ON o.companyid = i.companyid
   WHERE i.outletid IS NULL
ON CONFLICT DO NOTHING;

-- NOTA sobre `item.outletid`: se CONSERVA sin drop, igual que `contact.outletid`
-- en la mig 66, pero queda CONGELADA — ningún camino de escritura la toca ya
-- (`ItemService::update()` la saca del patch, `VariantService` no la copia a las
-- variantes, `ItemImporter` manda `outletIds`), y `outletVisibilityClause()` no
-- la mira. Conserva su valor viejo en las filas existentes y queda NULL en las
-- nuevas; sigue viajando en el payload solo para consumidores legacy.
--
-- NO se mantiene como espejo de una "sucursal principal": el modelo nuevo no
-- tiene ese concepto (las N sucursales son equivalentes) y un espejo arbitrario
-- invitaría a que un lector nuevo la tome por la respuesta correcta. Un lector
-- que necesite las sucursales usa `item_outlet` o el campo `outletIds` del
-- payload.

-- ── 3. Sync: el vínculo es satélite del ítem (context/45, mig 139) ──────────
-- Cambiarle las sucursales a un ítem ES un cambio del ítem: bumpea
-- `item.updated_at` para que el delta del POS se entere. `fn_touch_parent()`
-- ya usa `fn_tenant_wall_clock(companyid)` — el reloj del tenant, no `now()`
-- crudo (ver mig 139 §Reloj: un `now()` a secas pierde writes en tenants con
-- timezone distinta a America/Asuncion).
DROP TRIGGER IF EXISTS trg_item_outlet_touch_item ON item_outlet;
CREATE TRIGGER trg_item_outlet_touch_item
AFTER INSERT OR UPDATE OR DELETE ON item_outlet
FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid');

-- ── 4. Mínimo-una sucursal: por qué el invariante NO vive acá ───────────────
-- Se evaluó un CONSTRAINT TRIGGER DEFERRABLE INITIALLY DEFERRED sobre
-- item_outlet que abortara el commit si un ítem quedaba con cero filas
-- (el DELETE+INSERT del replace de sucursales pasaría, porque se chequea al
-- commit). Se DESCARTÓ por un caso real que rompería:
--
--   `OutletsService::delete()` hace `DELETE FROM outlet` (hard delete,
--   api/lib/Outlets/OutletsService.php:311). El ON DELETE CASCADE de arriba
--   borraría las filas de item_outlet, y el constraint abortaría el borrado
--   de la sucursal para cualquier ítem que la tuviera como única — es decir,
--   el invariante de ítems bloquearía una operación del módulo de sucursales.
--
-- El invariante se aplica entonces en el write-path (`ItemService::create()/
-- update()` rechazan lista vacía con 422). Queda UN hueco conocido y
-- documentado: borrar una sucursal puede dejar en cero a los ítems que solo
-- vivían ahí. Cerrarlo requiere reasignar esos ítems dentro de
-- `OutletsService::delete()` (decisión de negocio: ¿a qué sucursal caen?) —
-- fuera del alcance de esta migración.

COMMIT;
