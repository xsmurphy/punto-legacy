# Plan: Catálogo M2M con unicidad por nombre

**Estado:** En ejecución (iniciado 2026-06-19).

## Decisiones (cerradas con el owner)

1. **M2M**: `category`, `brand`, `tag` son many-to-many con `item`. Pueden coexistir múltiples por item.
2. **`tax` queda 1:1** (FK directa `item.taxId`). Coherente con facturación: una sola alícuota por línea de venta.
3. **Unicidad**: no pueden existir dos rows con el mismo `lower(name)` dentro de la misma `companyId` para `category`, `brand`, `tax`, `tag`, ni para `taxonomy(taxonomyType, companyId, lower(taxonomyName))`.
4. **Tabla `tag` dedicada** (no seguir en `taxonomy(type='tag')`), con trigger bidireccional al estilo de `category/brand/tax`.
5. **Duplicados existentes**: se mergean (mantener el más viejo, reasignar FKs, borrar el resto). Imprescindible antes del UNIQUE.

## Por qué este modelo

- El bug "se duplican categorías en cada import de Excel" tenía su raíz en `Taxonomy::getIdOrInsert()` (fix `6ec65bb`). Pero aunque ya no se dupliquen vía importer, **nada impedía** que el operador cree manualmente dos "Materia Prima" desde la UI o que un endpoint legacy las cree. La única defensa real es el UNIQUE constraint.
- El modelo 1:1 ya era una desviación: `item_category` y `item_brand` existen como m2m desde las migs 16 y 22, pero la UI nunca expuso multi-select y el importer/POS solo escribían el "primario". Esta sesión cierra esa brecha.

## Slices

| # | Slice | Resultado |
|---|---|---|
| A | **BD** — migs 37/38/39: cleanup dups, UNIQUE, tabla tag + item_tag | Schema con unicidad y m2m completo |
| B | **API** — getIdOrInsert con normalización + ON CONFLICT, endpoints `/v1/tags`, escritura m2m del item | Backend escribe/lee m2m sin duplicar |
| C | **/settings/catalog** tab Etiquetas + UNIQUE feedback en errores | UI del catálogo completa |
| D | **Item form** — 3 multi-select shadcn con creación inline | Operador elige varias categorías/marcas/etiquetas |
| E | **ItemImporter** — listas por `\|` en CATEGORIA / MARCA | Imports masivos respetan m2m |
| F | **Doc** (este archivo) — registro vivo del plan + notas de migración | Trazabilidad |

### Slice A — BD (3 migraciones)

**Mig 37: cleanup duplicados** (irreversible — se borran rows; los items se reasignan al ID más viejo).

Para cada `(companyId, taxonomyType, lower(taxonomyName))` con count > 1, manteniendo el `taxonomyId` cuya `taxonomy.taxonomyId::text` es más viejo (criterio: orden de inserción aproximado por UUID + fallback a `MIN(taxonomyId)`):

- UPDATE `item.categoryId/brandId/taxId/locationId` → ID conservado
- UPDATE `outlet.taxId/categoryId` → ID conservado
- UPDATE `toCategory.categoryId` → ID conservado
- UPDATE `item_category.categoryId` con ON CONFLICT DO NOTHING (puede chocar con un row primario)
- UPDATE `item_brand.brandId` con ON CONFLICT DO NOTHING
- UPDATE `item.data.tags` JSONB array — reemplazar cada UUID duplicado por el conservado
- DELETE `taxonomy` filas duplicadas (los triggers bidireccionales propagan el DELETE a `category/brand/tax`)

**Mig 38: UNIQUE constraints.**

- `taxonomy`: partial unique index `(taxonomyType, companyId, lower(taxonomyName))` WHERE `companyId IS NOT NULL` (globales con `companyId IS NULL` pueden duplicarse entre sí; cada tenant tiene su propio set único).
- `category`: unique `(companyId, lower(name))`.
- `brand`: unique `(companyId, lower(name))`.
- `tax`: unique `(companyId, lower(name))`.

**Mig 39: tabla `tag` + `item_tag` + triggers.**

- Tabla `tag` con backfill desde `taxonomy WHERE taxonomyType='tag'` (mismo UUID).
- Tabla `item_tag(itemId, tagId)` con backfill desde `item.data.tags` JSONB array.
- Trigger `sync_taxonomy_to_tag` + `sync_tag_to_taxonomy` (mismo patrón que mig 22).
- UNIQUE `(companyId, lower(name))` en `tag`.
- Política: `data.tags` JSONB se mantiene durante la transición pero `item_tag` es la fuente de verdad nueva. El POS y el importer migran en Slice B y E.

### Slice B — API

- `Taxonomy::getIdOrInsert()`: ya usa `COMPANY_ID` (fix `6ec65bb`). Añadir normalización case-insensitive (`WHERE LOWER(taxonomyName) = LOWER(?)`) y manejar `ON CONFLICT DO NOTHING` al insertar — si dos requests concurrentes piden la misma categoría, gana el primero, el segundo re-SELECT.
- `api/v1/tags.php`: CRUD igual a `brands.php`/`categories.php`.
- Escritura de item (`ItemService::update`): sincronizar `item_category`, `item_brand`, `item_tag` (DELETE WHERE itemId + INSERT múltiple). Mantener `item.categoryId`/`brandId` apuntando al "primario" (primer ID del array) para compat legacy.
- `presentItem()`: devolver `categoryIds[]`, `brandIds[]`, `tagIds[]` (arrays UUID) además de los nombres resueltos.

### Slice C — UI /settings/catalog

- `panel-next/hooks/use-tags.ts` (paridad con `use-brands.ts`).
- `panel-next/lib/types/tag.ts`.
- Agregar tab "Etiquetas" en `catalog/page.tsx`, grid pasa a 4-col, ícono `Tags` de lucide.
- Mostrar mensaje del backend si el UNIQUE rebota: `toast.error("Ya existe una categoría con ese nombre")`.

### Slice D — Item form

- Componente `MultiTaxonomyCombobox<T>` reusable (shadcn `Command` + `Popover` + chips):
  - Lista opciones de `useCategories()`/`useBrands()`/`useTags()`.
  - Permite crear nueva opción inline (fallback al `create` mutation del hook respectivo).
  - Emite `string[]` (UUIDs).
- `contacts/[id]/page.tsx` (form de item — verificar path real) y `contact-detail-view.tsx` usan el componente para categoría/marca/etiqueta. Impuesto sigue siendo `Select` 1:1.

### Slice E — ItemImporter

- Columnas `CATEGORIA` y `MARCA` aceptan lista separada por `|` (mismo separador que ETIQUETAS).
- El campo `categoryId` del record se pisa por el "primario" (primer elemento); los demás van a `item_category` via Service.

## Notas de operación

- **Mig 37 es irreversible**. Antes del deploy en prod: hacer dump de `taxonomy`, `item_category`, `item_brand`, `data.tags` por si hay que rebobinar manualmente.
- **Mig 38 puede fallar** si quedó algún dup que la 37 no agarró (ej. INSERTs concurrentes mientras corría la 37). En ese caso, repetir manualmente las queries de la 37 y reintentar la 38.
- Los **3 triggers bidireccionales** (`category`, `brand`, `tax`, `tag`) propagan DELETEs — borrar de `taxonomy` borra de las 3-4 tablas dedicadas. El cleanup de mig 37 lo aprovecha.

## Cronología de commits

- `6ec65bb` — fix `getTaxonomyIdOrInsert` usando `COMPANY_ID`. Pre-requisito.
- `5a804f6` — Slice A: migs 37 (dedup), 38 (UNIQUE), 39 (tag + item_tag).
- `45d8b9a` — Slice B: `Taxonomy::getIdOrInsert` con LOWER + retry, `TagService`, `/v1/tags`, branches `resource=brands|tags` y helpers `fetchItemBrands/Tags` en `items.php`.
- `2c574d2` — Slice C: tab Etiquetas en `/settings/catalog` (grid 4-col), `use-tags` + `lib/types/tag`.
- `3519013` — Slice D: `BrandsPicker` (con isPrimary) + `TagsPicker` (sin isPrimary) integrados en el form del item. Hidratación desde `brandsDetail`/`tagsDetail` con fallback al legacy.
- _(este commit)_ — Slice E: ItemImporter acepta `|` en CATEGORIA/MARCA, escribe los m2m (item_category/item_brand/item_tag) después del update con isPrimary=true para el primero.
