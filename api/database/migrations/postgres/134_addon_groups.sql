-- 134_addon_groups.sql
-- Add-ons por producto (context/41, F1). Dos tablas: grupo de opciones y
-- opciones del grupo. Sin tabla N:M — D1 del plan descartó reusabilidad
-- entre productos: cada grupo cuelga directo del ítem dueño (`itemId`,
-- ON DELETE CASCADE) y no hay efecto dominó entre fichas. "Copiar grupos
-- desde otro producto" (F2, panel) es una COPIA real de filas, no una
-- referencia compartida.
--
-- El add-on ES un producto del catálogo (`addon_group_option.itemId` →
-- item), no un texto suelto: hereda stock, receta, costo e impuestos, y la
-- venta lo descuenta con la misma maquinaria que cualquier ítem (F3).
--
-- Tablas nuevas → camelCase QUOTED (convención desde 2026-06-23, ver
-- context/08-convenciones-criticas.md §44 y 46_inventory_count.sql como
-- referencia de estilo). Las tablas legacy referenciadas (`item`, `company`)
-- siguen con columnas sin quotes: PG resuelve el identifier sin comillas a
-- lowercase en ambos lados, así que `REFERENCES item(itemId)` matchea la
-- columna real `itemid` sin problema.
--
-- priceDelta (D2, owner 2026-08-14): el precio lo define CADA OPCIÓN, no un
-- % de descuento sobre la suma. 0 = no suma, >0 = recarga. isLocked implica
-- isDefault (un add-on fijo está siempre elegido) — el guard vive en
-- AddonService, no se confía en que la UI lo mande bien.

BEGIN;

CREATE TABLE IF NOT EXISTS "addon_group" (
    "groupId"   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    "companyId" UUID NOT NULL REFERENCES company(companyId),
    -- Dueño del grupo. CASCADE: borrar el producto se lleva sus grupos —
    -- no quedan grupos huérfanos apuntando a un item inexistente.
    "itemId"    UUID NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
    "name"      VARCHAR(80) NOT NULL,
    -- 0 = grupo opcional. minSelect > 0 = el POS no deja confirmar la línea
    -- sin elegir (F4) — ej. "Bebida: min 1 max 1" es un radio obligatorio.
    "minSelect" SMALLINT NOT NULL DEFAULT 0,
    -- NULL = sin tope de selecciones distintas dentro del grupo.
    "maxSelect" SMALLINT,
    "sort"      SMALLINT NOT NULL DEFAULT 0,
    "status"    BOOLEAN NOT NULL DEFAULT TRUE,
    CHECK ("minSelect" >= 0),
    CHECK ("maxSelect" IS NULL OR "maxSelect" >= GREATEST("minSelect", 1)),
    CHECK (btrim("name") <> '')
);

CREATE INDEX IF NOT EXISTS "ix_addon_group_item"    ON "addon_group" ("itemId");
CREATE INDEX IF NOT EXISTS "ix_addon_group_company" ON "addon_group" ("companyId");

CREATE TABLE IF NOT EXISTS "addon_group_option" (
    "optionId"   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    "groupId"    UUID NOT NULL REFERENCES "addon_group"("groupId") ON DELETE CASCADE,
    -- El producto real que se agrega. RESTRICT (no CASCADE): borrar un
    -- producto que es opción de un add-on en otro ítem no debe desaparecer
    -- la fila en silencio — el borrado de items es soft-delete (itemStatus)
    -- salvo casos ya archivados, y AddonService valida itemStatus=1 al leer.
    "itemId"     UUID NOT NULL REFERENCES item(itemId) ON DELETE RESTRICT,
    -- 0 = no suma al precio del producto/combo (D2). El combo/producto tiene
    -- su propio precio; cada opción decide individualmente si recarga.
    "priceDelta" NUMERIC(14,2) NOT NULL DEFAULT 0,
    -- Preseleccionado al abrir el modal (F4). isLocked=TRUE fuerza isDefault
    -- =TRUE — un fijo está siempre elegido y no se puede destildar.
    "isDefault"  BOOLEAN NOT NULL DEFAULT FALSE,
    "isLocked"   BOOLEAN NOT NULL DEFAULT FALSE,
    -- Cuántas veces se puede repetir esta misma opción en la línea (ej. "2x
    -- panceta"). 1 = sin repetición.
    "maxQty"     SMALLINT NOT NULL DEFAULT 1,
    "sort"       SMALLINT NOT NULL DEFAULT 0,
    CHECK ("maxQty" >= 1),
    CHECK ("priceDelta" >= 0),
    CHECK (NOT "isLocked" OR "isDefault")
);

CREATE INDEX IF NOT EXISTS "ix_addon_group_option_group" ON "addon_group_option" ("groupId");
CREATE INDEX IF NOT EXISTS "ix_addon_group_option_item"  ON "addon_group_option" ("itemId");

COMMENT ON TABLE "addon_group" IS
  'Grupo de opciones de add-on de UN producto (context/41 D1). No reusable entre productos.';
COMMENT ON TABLE "addon_group_option" IS
  'Opción de un addon_group: apunta a un producto real del catálogo. priceDelta=0 no suma al precio (D2).';

COMMIT;
