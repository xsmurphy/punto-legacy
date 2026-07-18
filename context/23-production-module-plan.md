# Módulo de Producción — plan (F0/F1/F2)

Plan cerrado por el owner 2026-07-17. Decisiones abajo NO se relitigan; solo
se ejecutan. Fase actual: **F0** (consolidación de recetas).

## Contexto — el bug que motiva F0

Hay DOS tablas de recetas:

- **`toCompound`** (legacy) — la LEEN `Inventory::getCompoundsArray`,
  `getProductionCOGS`, `getComboCOGS` (`api/lib/App/Domain/Inventory.php`),
  `SaleService::persistItemsAndStock`, `TransactionService` (void de venta)
  y `functions.php:2790` (otro path de void). Todos consumen el shape plano
  `{compoundId, toCompoundQty, ...}` vía `getCompoundsArray()`.
  Schema real (confirmado por código, NO por nombre de columna): `itemId` =
  **parentItemId** (el item combo/producción), `compoundId` = **childItemId**
  (el ingrediente/insumo), `toCompoundQty`, `toCompoundOrder`,
  `toCompoundPreselected`.
- **`item_compound`** (mig 19) — la ESCRIBE el editor del panel vía
  `ItemCompoundService` (`api/v1/items.php:434-479`). Columnas explícitas:
  `parentItemId`, `childItemId`, `quantity`, `sort`, `companyId`. Hoy NO la
  lee nadie del lado del negocio — solo el CRUD del editor.

**El editor escribe la nueva pero el negocio (venta, COGS, void) lee la
vieja** → las recetas editadas en el panel hoy NO afectan stock ni costos
real. Bug silencioso, no hay error visible.

Bug adicional: `getComboCOGS` (Inventory.php:143-161) usa `itemPrice`
(precio de VENTA) del ingrediente como "costo" — sobreestima el COGS de
combos porque suma precios de venta, no costos reales.

## F0 — recetas canónicas en `item_compound`

Objetivo: un solo lugar de verdad. El negocio (venta/COGS/void) pasa a leer
`item_compound`. `toCompound` queda de solo-lectura histórica, no se borra.

1. **Migración de datos** (`api/database/migrations/postgres/75_recipes_canonical.sql`):
   copia filas de `toCompound` → `item_compound` que no existan ya.
   - Map: `toCompound.itemId` → `item_compound.parentItemId`,
     `toCompound.compoundId` → `item_compound.childItemId`,
     `toCompound.toCompoundQty` → `item_compound.quantity`,
     `toCompound.toCompoundOrder` → `item_compound.sort`.
   - `companyId` sale del item padre (`item.companyId WHERE itemId = toCompound.itemId`).
   - Excluye filas con `toCompoundQty <= 0` o `toCompoundPreselected IS NOT
     NULL` (semántica de combo-picker legacy — el usuario elige 1 de N en
     runtime, no es un ingrediente fijo de receta).
   - `ON CONFLICT (parentItemId, childItemId) DO NOTHING` — idempotente,
     no pisa ediciones ya hechas en `item_compound`.
   - `toCompound` NO se borra ni se deprecia estructuralmente en F0.
2. **Switch de lectores**: `Inventory::getCompoundsArray()` pasa a leer
   `item_compound`, aliaseando `childItemId AS compoundId` y
   `quantity AS toCompoundQty` para preservar el shape plano que ya esperan
   ~20 call-sites (SaleService, TransactionService, functions.php:2790,
   getProductionCapacity/COGS/displayableCompounds). No se tocan esos
   call-sites. `getProductionCOGS`/`getComboCOGS` heredan el switch
   automáticamente (llaman a `getCompoundsArray` internamente). Fix de
   `getComboCOGS`: usa costo real del ingrediente (`stockOnHandCOGS` vía
   `getItemStock`, fallback `itemCost`), no `itemPrice`.
3. **Guard de ciclos** en `ItemCompoundService::add` — recorre
   `parentItemId → children` recursivo (scoped a companyId, límite de
   profundidad) antes de insertar; rechaza si el nuevo child ya es ancestro
   del parent (crearía un ciclo).
4. `CompoundService.php` (legacy, sin callers en todo el repo — confirmado
   por grep) marcado `@deprecated` → apunta a `ItemCompoundService`. No se
   borra (compat shim / referencia histórica).

### Fuera de alcance F0

- No se toca `manageStock` ni el refactor I0-I6 pendiente.
- No se borra `toCompound`.
- No se cambia el editor del panel (ya escribe `item_compound`, correcto).
- `CompanyAdminService::deleteCompany` (hard-delete cascade) sigue borrando
  `toCompound` por `compoundId`; no se le agrega limpieza de `item_compound`
  en F0 (FK ya tiene `ON DELETE CASCADE`/`RESTRICT` propios) — queda anotado
  como posible gap si el hard-delete de company falla por FK RESTRICT.

## F1 — backend de producción (siguiente fase, no arrancada)

- Tabla `production_order` — estados `draft → in_progress →
  completed|cancelled`. Dos flujos: producir-ahora (1 paso, atómico) y
  órdenes con seguimiento de estado.
- Producción con `outletId` + `locationId` opcional (destino del stock
  producido, igual que hoy en compras/ajustes).
- Tabla `waste_event` — merma real auditable, con `wasteReason` como
  taxonomy editable (`taxonomyType = 'wasteReason'`, mismo patrón que
  `location`/`category`/`tax` — ver mig 21/23), seedeada con motivos
  default. El % de merma de receta (`itemWaste`) sigue siendo el costo
  PLANIFICADO (afecta `getNeedWithWaste` en COGS); los `waste_event` son el
  registro REAL, separado, vía `manageStock(source='waste')`.
- COGS real: promedio ponderado del insumo al momento de consumir
  (`stockOnHandCOGS`, ya lo calcula `manageStock`); costo unitario de lo
  producido = total consumido / unidades producidas.
- Sub-recetas NO explotan recursivo: un ingrediente que es a su vez un item
  producido consume su PROPIO stock (ya debe estar producido). No hay
  producción en cascada automática en v1.
- Sin co-productos (1 orden → 1 item de salida) ni completado parcial en v1.
- Permiso nuevo: `production.manage`.
- Monta sobre `manageStock` actual — NO reabre el refactor I0-I6.

### Diferido a v2 (explícito, no implementar en F1)

- Co-productos / múltiples salidas por orden.
- Completado parcial de una orden (unidades producidas ≠ unidades planeadas).
- Explosión recursiva de sub-recetas (auto-producir insumos faltantes).
- Cleanup/borrado de `toCompound`.

## F2 — UI `/produccion` (no arrancada)

- Listado de órdenes de producción (`DataTable`, ver `context/14`).
- Registrar merma (formulario: item, cantidad, motivo desde taxonomy,
  outlet/location opcional).
- Botón "Producir" (flujo 1-paso) en el módulo de items o en `/produccion`.
- Reporte de producción (costo real vs planificado, merma real vs %).
