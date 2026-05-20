<!-- REGLA: Este doc captura el plan de refactor del módulo Items. Actualizar
     a medida que se completan fases. Cuando esté 100% completo, archivar a
     _session-log-archive y borrar este file. -->

# Refactor — Módulo Items (productos / servicios / insumos / combos)

> **Fecha del análisis**: 2026-05-19
> **Decisiones del usuario**:
> - Frontend objetivo: **SPA moderna** en `/panel/items-v2` paralela al legacy (stack a definir en Fase 4)
> - Arranque: **Fase 0 (quick wins) primero**

---

## Diagnóstico actual

**Tamaño**: 5.573 líneas en `a_items.php` + 5.578 en `a_itemsNew.php` (98% duplicados) + 3.755 líneas en JS legacy (3 archivos casi-idénticos). El módulo más grande del panel.

### Archivos involucrados

| Capa | Archivos | Estado |
|------|---------|--------|
| **PHP monolitos** | `a_items.php`, `a_itemsNew.php`, `inventory.php`, `inventory_count.php`, `a_bulk_production.php` | 🔴 Legacy, duplicado |
| **API envelope canónico** | `panel/API/get_items.php` | 🟡 1/N migrado |
| **API legacy** (acciones embebidas) | 22 `?action=*` en `a_items.php` | 🔴 Sin middleware, JSON plano |
| **JS** | `a_items.js`, `a_items2.js`, `a_itemsNew.js` (1.250 líneas c/u) + `items.js` (helpers) | 🔴 Triplicado + inline minify |
| **DB core** | `item` (28 columnas + JSONB `data`) | 🟡 Funcional con deudas |
| **DB relacionales** | `taxonomy`, `inventory`, `stock`, `stockTrigger`, `itemSold`, `toCompound`, `toCategory`, `toTag`, `toLocation` | 🟡 Funcional |

### Tipos de item (11 valores hardcodeados sin enum)

`product`, `service`, `compound`, `production`, `combo`, `precombo`, `comboAddons`, `direct_production`, `giftcard`, `group`, `dynamic`.

Más 4 flags booleanos: `itemCanSale`, `itemTrackInventory`, `itemIsParent`, `itemImage` (¡VARCHAR(10) `'false'` por default!).

### Dolores priorizados

| # | Dolor | Severidad |
|---|------|-----------|
| 1 | SQL injection en `IN(' . $upsells['names'] . ')` línea 1513 + 3 patterns más | 🔴 P0 |
| 2 | Duplicación 1:1 de `a_items*.php` y `a_items*.js` (~12k líneas redundantes) | 🔴 |
| 3 | Acciones API embebidas en a_items.php (`?action=update`, etc.) | 🔴 |
| 4 | `itemImage VARCHAR(10)='false'` legacy | 🟡 |
| 5 | Pricing fragmentado: `itemPrice` columna vs `itemPricePercent`/`itemPriceType` en JSONB | 🟡 |
| 6 | Polimorfismo sin FK en `toCategory`/`toTag` | 🟡 |
| 7 | `taxonomy` sobrecargada (4 conceptos en 1 tabla) | 🟡 |
| 8 | `itemType` sin CHECK constraint | 🟡 |
| 9 | Indexes faltantes (JSONB sin GIN, `idx_item_supplier`) | 🟢 |
| 10 | JS inline minificado vía `minifyJS()` | 🟢 |

---

## Arquitectura objetivo

```
Frontend (SPA moderna en /panel/items-v2)
         ↓ JSON envelope canónico
BFF (PHP) — panel/lib/items/*
  - ItemRepository, ItemService, PricingService,
    StockService, CompoundService
         ↓ SQL parametrizado
PostgreSQL
  - item normalizado, JSONB solo para extensiones reales
  - item_variant + item_compound (junctions explícitas)
  - taxonomy split → category, brand, tag, tax_rate
```

### Endpoints objetivo (`/api/v1/items`)

```
GET    /api/v1/items                  paginado + filter
GET    /api/v1/items/{id}
POST   /api/v1/items
PUT    /api/v1/items/{id}
DELETE /api/v1/items/{id}             soft-delete
POST   /api/v1/items/bulk             create/update masivo
POST   /api/v1/items/{id}/image
GET    /api/v1/items/{id}/compounds
POST   /api/v1/items/{id}/stock/{op}  add | remove
POST   /api/v1/items/import/csv
GET    /api/v1/items/export/csv
```

---

## Plan de migración en fases

### Fase 0 — Quick wins ✅ (en curso)

1. Eliminar `a_itemsNew.php` + `a_itemsNew.js` + `a_items2.js`
2. Fix 4 SQL injection (a_items.php líneas 20, 50, 1513, 2091)
3. Migration `itemImage VARCHAR(10)` → `BOOLEAN`

**Esfuerzo**: ~3h. Cierra security + reduce módulo a la mitad.

### Fase 1 — Extraer dominio a `panel/lib/items/`

Sin tocar a_items.php. Crear:
- `ItemRepository.php` — SELECT/INSERT/UPDATE parametrizados
- `ItemService.php` — CRUD + business rules
- `CompoundService.php` — toCompound logic
- `StockService.php` — stockTrigger + stock writes
- `PricingService.php` — price + tax + discount calculator

a_items.php delega a estos. Saca 1.500-2.000 líneas del monolito.

**Esfuerzo**: ~2 días.

### Fase 2 — API canónicos paralelos (no rompen legacy)

Crear `panel/API/v1/items/*` con envelope `apiOk()`. Reusan los Services de Fase 1.

**Esfuerzo**: ~3-4 días.

### Fase 3 — Normalizar schema (incremental dual-write)

1. CHECK constraint en `itemType`
2. Crear tablas `item_compound` y `item_variant` con FKs explícitas
3. Split `taxonomy` → `item_category`, `item_brand`, `tax_rate`, `item_tag`
4. GIN index sobre `item.data`

**Esfuerzo**: ~1 semana.

### Fase 4 — Frontend SPA en `/panel/items-v2`

Stack a definir (React / Vue / Svelte). Consume `/api/v1/items`. Convive con `/a_items` legacy.

**Esfuerzo**: ~2 semanas + decisión de stack.

### Fase 5 — Decommission

Eliminar `a_items.php` (22 acciones legacy). Mantener solo el shell HTML del modal.

**Esfuerzo**: ~1 día.

---

## Estado de avance

- [x] **Fase 0** — completada 2026-05-19
- [ ] **Fase 1** — en ejecución 2026-05-19
  - [x] **1A**: `ItemRepository` + `ItemService` + refactor de `?action=insertBtn` (POC)
  - [x] **1B-1**: usar `ItemService->update()` en `?action=update` (fix SQL injection + JSONB)
  - [x] **1C**: `CompoundService` + `StockService` + `UpsellService`
  - [x] **1D-1**: tabla `itemLocation` + `LocationService` (multi-depósito por item)
  - [x] **1D-2**: refactor UI a_items para multi-depósito (checkbox + radio default por outlet)
  - [x] **1D-3**: `produce()` + voidTransaction usan `resolveItemLocation()` (LocationService + fallback item.locationId)
  - [x] **1D-4**: `getItemStock` y `manageStock` filtran por `locationId` → saldos independientes por depósito. Fix bugs PG: `iftn(_, NULL)` ya no devuelve `""`, `getItemStock` parametriza outletId UUID. Verificado E2E: 100/50 → -2 desde Almacén → 98/50 → cambia default → -2 desde Cocina → 98/48.
- [x] **Fase 2** — APIs `/API/v1/items/*` canónicos
  - GET/POST/PUT/DELETE sobre `/API/v1/items` con envelope `apiOk()`
  - Sub-recurso `?resource=locations` para gestionar itemLocation
  - `apiMiddleware` extendido con fallback de sesión PHP (panel logueado puede consumir su API sin JWT/api_key)
  - [ ] **1B-2**: extraer normalización masiva del POST → `ItemService::buildUpdateRecord()`
- [ ] Fase 2 — pendiente
- [ ] Fase 3 — pendiente
- [ ] Fase 4 — pendiente (decisión de stack SPA)
- [ ] Fase 5 — pendiente
