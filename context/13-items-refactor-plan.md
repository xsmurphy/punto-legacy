# Plan — Refactor del módulo de Artículos / Servicios

> **Creado:** 2026-06-11. **Estado:** Slice A ✅ (commit `b8b0abc`). Slice B es el próximo.
>
> Esta es la próxima ola grande del rewrite del panel después de Settings.
> Se inicia tras tener Items CRUD básico funcional pero limitado (commits
> hasta `0120267`). El usuario pidió análisis profundo antes de codear porque
> el dominio está enredado en el legacy.

---

## Principio rector (decisión del usuario)

> "Justamente lo que no quiero es que debamos crear reglas condicionales
> basadas en diferentes configuraciones para definir si un item cumple o
> no una función, prefiero que cada item sea independiente dentro de su tipo."

**`itemKind` es la verdad.** Cada kind tiene su propio modelo de datos, su
propio form, su propia validación. NO se deduce qué es un item desde
combinaciones de flags. NO hay "if itemType=product Y itemTrackInventory=0
entonces es servicio" — si es servicio, se crea con `kind='servicio'` y
listo.

Trade-off aceptado: más TypeScript (12 interfaces vs una con opcionales)
y más form components (12 vs uno con conditional rendering), a cambio
de cero ambigüedad y reportes/módulos más simples.

---

## Los 12 kinds

| # | Kind | Descripción funcional |
|---|---|---|
| 1 | `producto` | Físico, vendible, comprable, con stock. Puede tener toppings/addons. |
| 2 | `insumo_stock` | Comprable, no vendible, con stock. Apto como ingrediente de recetas. (Ej. queso en fetas) |
| 3 | `insumo_sin_stock` | No vendible ni comprable. Apto solo como ingrediente. (Ej. hora hombre en una receta) |
| 4 | `insumo_control` | Comprable, no vendible. Stock por descuento aproximado (no por venta). No va en recetas. (Ej. limpiador de piso) |
| 5 | `produccion_directa` | Vendible, sin stock propio, receta consumida al vender. (Ej. café, ensalada armada al pedido) |
| 6 | `produccion_previa` | Vendible, con stock propio, receta consumida en producción previa a la venta. (Ej. dulce de leche elaborado en lotes) |
| 7 | `servicio` | Vendible sin stock, con receta opcional. Tiene duración. (Ej. masaje individual) |
| 8 | `servicio_sesiones` | Pack de N sesiones vendido en bloque. Cada sesión se consume vía módulo Citas. |
| 9 | `combo_fijo` | Vendible, precio único, componentes obligatorios sin elección del cliente. |
| 10 | `combo_dinamico` | Vendible, precio base + extras según selección del cliente con `min/max` por grupo. |
| 11 | `descuento` | Item POS-selectable que aplica descuento al ticket. |
| 12 | `giftcard` | Pre-pagada con código canjeable. |

### Distinción explícita por reglas (no deducción)

- **producto vs combo_dinamico**: si todos los grupos modifier tienen
  `minSelections = 0` → producto (vendible solo o con extras). Si al
  menos un grupo tiene `minSelections >= 1` → combo_dinamico (hay que
  elegir algo). La distinción la enforce el form de cada kind, no se
  deduce al leer.
- **insumo_control vs insumo_stock**: control NO participa en recetas
  (no se puede agregar como component a otro item). Stock se descuenta
  con disparador aproximado (TBD: cron, ajuste manual del operador, o
  proporcional a compras). Pendiente confirmar flujo.

---

## Schema — 4 migrations nuevas

### Migration 15 — `itemKind` canonical

```sql
ALTER TABLE item ADD COLUMN itemKind VARCHAR(30);

-- Backfill UNA VEZ desde el estado actual.
UPDATE item SET itemKind = CASE
  WHEN itemType = 'discount'   THEN 'descuento'
  WHEN itemType = 'giftcard'   THEN 'giftcard'
  WHEN itemType = 'combo'      THEN 'combo_fijo'  -- combo viejo lo trato como fijo; los dinámicos se pasan manual
  WHEN itemProduction > 0      THEN 'produccion_previa'
  WHEN itemType = 'production' THEN 'produccion_previa'
  WHEN itemCanSale = 0 AND itemTrackInventory > 0 THEN 'insumo_stock'
  WHEN itemCanSale = 0 AND itemTrackInventory = 0 THEN 'insumo_sin_stock'
  WHEN itemCanSale > 0 AND itemTrackInventory > 0 THEN 'producto'
  WHEN itemCanSale > 0 AND itemTrackInventory = 0 THEN 'servicio'
  ELSE 'producto'
END;

ALTER TABLE item ALTER COLUMN itemKind SET NOT NULL;
ALTER TABLE item ADD CONSTRAINT item_kind_valid CHECK (
  itemKind IN ('producto','insumo_stock','insumo_sin_stock','insumo_control',
               'produccion_directa','produccion_previa','servicio',
               'servicio_sesiones','combo_fijo','combo_dinamico',
               'descuento','giftcard')
);
CREATE INDEX idx_item_kind ON item(itemKind);
```

Los flags viejos (`itemType`, `itemCanSale`, `itemTrackInventory`,
`itemProduction`) se MANTIENEN para que el legacy panel/POS sigan
funcionando. Dual-write: panel-next + endpoints nuevos escriben kind +
los flags legacy en sync. DROP al final cuando el legacy muera.

### Migration 16 — `item_category` many-to-many

```sql
CREATE TABLE item_category (
  itemId     UUID NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  categoryId UUID NOT NULL REFERENCES taxonomy(taxonomyId) ON DELETE CASCADE,
  isPrimary  BOOLEAN DEFAULT FALSE,
  PRIMARY KEY (itemId, categoryId)
);
CREATE INDEX idx_item_category_cat ON item_category(categoryId);

INSERT INTO item_category (itemId, categoryId, isPrimary)
SELECT itemId, categoryId, TRUE FROM item WHERE categoryId IS NOT NULL;
-- Dejamos item.categoryId temporal para legacy compat.
```

`isPrimary` = la categoría "principal" del item (para reports que
necesitan UNA sola, ej. dashboard top categorías). Los demás módulos
pueden hacer JOIN libremente.

### Migration 17 — Modifier groups (toppings, recetas, combos)

```sql
-- Grupo de modificadores. Un item puede tener N grupos (Salsas, Quesos, Bebida...).
CREATE TABLE item_modifier_group (
  id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  parentItemId    UUID NOT NULL REFERENCES item(itemId) ON DELETE CASCADE,
  name            VARCHAR(100) NOT NULL,          -- "Salsas", "Quesos", "Bebida"
  purpose         VARCHAR(20) NOT NULL,           -- 'recipe' | 'selection'
  minSelections   INT NOT NULL DEFAULT 0,
  maxSelections   INT,                            -- null = ilimitado
  sortOrder       INT DEFAULT 0,
  companyId       UUID NOT NULL REFERENCES company(companyId),
  CHECK (purpose IN ('recipe','selection')),
  CHECK (minSelections >= 0),
  CHECK (maxSelections IS NULL OR maxSelections >= minSelections)
);
CREATE INDEX idx_modifier_group_parent ON item_modifier_group(parentItemId);

-- Items que pueden ser elegidos dentro del grupo.
CREATE TABLE item_modifier_component (
  id                 UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  groupId            UUID NOT NULL REFERENCES item_modifier_group(id) ON DELETE CASCADE,
  componentItemId    UUID NOT NULL REFERENCES item(itemId),
  quantity           DECIMAL(10,3) NOT NULL DEFAULT 1,
  isDefault          BOOLEAN DEFAULT FALSE,
  extraPrice         DECIMAL(15,2) NOT NULL DEFAULT 0,  -- 0 = sin costo extra; > 0 = suma al precio del padre
  sortOrder          INT DEFAULT 0
);
CREATE INDEX idx_modifier_component_group ON item_modifier_component(groupId);
```

Sólo 2 valores de `purpose`:
- `recipe` — consume del stock automáticamente al vender, invisible al cliente.
- `selection` — visible en POS, cliente elige según min/max.

`extraPrice` numérico simple, no flag + price separados.

### Migration 18 — Pack sessions

Para `servicio_sesiones`:

```sql
CREATE TABLE pack_session (
  packItemId       UUID PRIMARY KEY REFERENCES item(itemId) ON DELETE CASCADE,
  sessionItemId    UUID NOT NULL REFERENCES item(itemId),  -- el item "Sesión individual"
  totalSessions    INT NOT NULL,                            -- ej. 10
  companyId        UUID NOT NULL REFERENCES company(companyId)
);

CREATE TABLE session_consumption (
  id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  packTransactionId        UUID NOT NULL,                   -- la venta del pack
  consumedAt               TIMESTAMPTZ NOT NULL DEFAULT now(),
  consumedOutletId         UUID,
  consumedAppointmentId    UUID,                            -- FK al módulo Citas
  companyId                UUID NOT NULL REFERENCES company(companyId)
);
CREATE INDEX idx_session_consumption_pack ON session_consumption(packTransactionId);
```

Pack es 1 fila en `item`, las sesiones consumidas se trackean en
`session_consumption`. No se pre-crean N items hijos.

---

## Campos extra que viven en JSONB (sin migration)

- `itemDuration` (entero, minutos) — usado por Citas y KDS. JSONB.
- `data.tags` (array de strings) — etiquetas libres (verano2024, popular).
- `availability` (objeto con días + horarios) — disponibilidad temporal.
- `currencies` (objeto código→precio) — cotizaciones multi-moneda.

---

## Backend — endpoints nuevos y modificaciones

### Endpoints que se refactorean

- `GET /v1/items` → `presentItem()` agrega `kind`, `categories[]`,
  `tags[]`, `modifierGroups[]` (resumen sin components).
- `GET /v1/items?id=...` → agrega lo anterior + `modifierGroups`
  con sus `components` anidados + `packSession` config si aplica.
- `POST /v1/items` → requiere `kind` y aplica validación per-kind
  (servicio sin price → 422, etc.). Sin `kind` default → 'producto'.
- `PUT /v1/items?id=...` → rechaza cambios de `kind` con 409 Conflict.
  Un item NO se transforma de servicio a producto; se archiva y se
  crea otro.

### Endpoints nuevos

- `GET/PUT /v1/items/{id}/categories` — m2m editor.
- `GET/POST/PUT/DELETE /v1/items/{id}/modifier-groups` — CRUD de grupos.
- `GET/POST/PUT/DELETE /v1/items/{id}/modifier-groups/{groupId}/components`
  — CRUD de components dentro del grupo.
- `GET/POST /v1/items/{id}/pack-session` — config del pack para
  `servicio_sesiones`.
- `POST /v1/sessions/{packTxId}/consume` — Citas marca una sesión
  como consumida.

---

## UI panel-next — Form components dedicados

### Lista `/items` refactoreada

- Filter "Tipo" con los 12 kinds (lectura directa de `item.kind`).
- Columna Tipo muestra el kind con icono distintivo.
- Filtros adicionales: categoría (multi-select desde m2m), tag.

### Creación `/items/new`

- **Paso 1 — elegir kind**: grilla de tarjetas con icono + nombre +
  descripción corta. Click → paso 2.
- **Paso 2 — form específico del kind elegido**: componente dedicado.

No más "form genérico con secciones que aparecen y desaparecen". Cada
kind tiene su propia experiencia.

### Edición `/items/[id]`

Form del kind correspondiente (lee `item.kind` y renderiza el
componente correcto).

### Tabs por kind

| Kind | Tabs |
|---|---|
| producto | Perfil · Precio · Stock · Modificadores · Categorías-Tags · Disponibilidad · Cotizaciones |
| insumo_stock | Perfil · Costo · Stock · Categorías-Tags |
| insumo_sin_stock | Perfil · Costo · Categorías-Tags |
| insumo_control | Perfil · Costo · Stock-aproximado · Categorías-Tags |
| produccion_directa | Perfil · Precio · Receta · Modificadores · Duración · Categorías-Tags · Cotizaciones |
| produccion_previa | Perfil · Precio · Receta · Stock · Modificadores · Duración · Categorías-Tags · Cotizaciones |
| servicio | Perfil · Precio · Duración · Receta opcional · Modificadores · Categorías-Tags · Disponibilidad |
| servicio_sesiones | Perfil · Precio · Sesiones (cuántas + item de sesión individual) · Categorías-Tags |
| combo_fijo | Perfil · Precio · Componentes fijos · Categorías-Tags |
| combo_dinamico | Perfil · Precio base · Grupos de selección · Categorías-Tags |
| descuento | Perfil · Descuento (monto/porcentaje) |
| giftcard | Perfil · Valor · Vigencia |

---

## TypeScript types — discriminated union

Una interfaz POR kind con SUS campos específicos. Sin opcionales
abusivos. Sin `[key: string]: unknown`.

```ts
type ItemAny =
  | ProductoItem
  | InsumoStockItem
  | InsumoSinStockItem
  | InsumoControlItem
  | ProduccionDirectaItem
  | ProduccionPreviaItem
  | ServicioItem
  | ServicioSesionesItem
  | ComboFijoItem
  | ComboDinamicoItem
  | DescuentoItem
  | GiftcardItem

interface InsumoStockItem {
  kind: "insumo_stock"
  itemId: string
  name: string
  sku: string
  cost: number
  uom: string
  supplierId: string
  brandId: string
  waste: number
  categories: string[]
  tags: string[]
  status: boolean
  // NO existe price, taxId, taxIncluded, discount, commission, ecom, featured.
  // Si querés esos, no es un insumo.
}
```

Cada form component es CLOSED — no conditionals, solo los fields para
ese kind.

---

## Decisiones que el usuario ya confirmó

1. `itemKind` columna canonical (no flags ortogonales).
2. Cada kind tiene su propio form dedicado, no shared con conditional rendering.
3. Hay 12 kinds (lista cerrada arriba).
4. Tabla `item_modifier_group` + `item_modifier_component` separadas.
5. `purpose` con 2 valores: `recipe` | `selection`.
6. `extraPrice` como número (no flag+price).
7. `itemDuration` en JSONB existente (cero migration).
8. Tags como JSONB array.
9. Many-to-many categorías con `isPrimary`.

## Decisiones confirmadas (2026-06-11)

| # | Decisión |
|---|---|
| 1 | `insumo_control` se decrementa igual que otros ítems: ventas POS, ajuste manual, conteo físico, producción. La diferencia es que NO va en recetas. |
| 2 | Combos legacy → `combo_fijo` por default. Proyecto en desarrollo, no hay datos reales. |
| 3 | Duración combos = sumatoria de duraciones de servicios componentes. |
| 4 | Dimensiones extra confirmadas: `minDaysBetweenSessions` en `pack_session` (migration 18) + `validFrom/validUntil` en JSONB (sin migration). |

---

## Orden de ejecución del refactor

Por slices independientes deployables. Cada uno commiteable sin romper
el legacy gracias al dual-write y al keep de los flags antiguos.

### Slice A — Schema + dual-write base ✅ commit `b8b0abc`

1. ✅ Migration 15 (itemKind canonical) + 16 (item_category m2m).
2. ✅ `presentItem()` agrega `kind`, `categories[]`, `tags[]`.
3. ✅ POST/PUT dual-write kind + flags legacy. PUT rechaza cambio de kind (409).
4. ✅ UI panel-next lista usa `kind` directo. KIND_META con 12 kinds.
5. ⬜ Editor categorías m2m UI (pendiente — requiere UI nueva en [id]/page.tsx).

### Slice B — Modifier groups (toppings + recetas + combos)

1. Migration 17 (modifier_group + component).
2. Endpoints CRUD `/v1/items/{id}/modifier-groups[*/components]`.
3. UI editor de modifier groups con drag-drop o table-like, integrado
   en los kinds que lo necesitan.
4. POS legacy actualiza al validar selección por `minSelections`/`maxSelections`.

### Slice C — Sessions (pack de N sesiones)

1. Migration 18 (pack_session + session_consumption).
2. Endpoints config del pack y consume.
3. UI editor del pack.
4. Integración con módulo Citas.

### Slice D — Form components dedicados por kind

1. Refactor `app/(panel)/items/[id]/page.tsx` para router por kind.
2. 12 form components, uno por kind, en `components/items/forms/`.
3. Validación zod per-kind.
4. Sacar la lógica `inferKind` y `KIND_META.fields` que asume conditional rendering.

### Slice E — Killshot del legacy

1. Refactor de queries del legacy para usar `itemKind` en lugar de los
   flags. Reports primero, POS después.
2. Migration final: DROP de `itemType`, `itemCanSale`, `itemTrackInventory`,
   `itemProduction`, `item.categoryId`.

---

## Cómo continuar en la próxima sesión

1. Leer este doc + `context/12-panel-rewrite.md` (estado general del
   rewrite hasta hoy).
2. Revisar memorias relevantes con `MEMORY.md` (especialmente
   `feedback-shadcn-first`, `feedback-data-tables-convention`,
   `feedback-legacy-as-reference`).
3. El usuario debe responder las **4 decisiones pendientes** de arriba.
4. Con respuestas en mano, arrancar por **Slice A**.

Estado del repo en el momento del cierre: commit `0120267` deployado,
items y settings funcionando con limitaciones documentadas en este
plan.
