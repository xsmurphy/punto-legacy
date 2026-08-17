# 01 — Catálogo de ítems

> Estado del doc: borrador (verificado contra código leyendo fuente, sin correr nada)
> Responsable de la última verificación: sesión 2026-08-17

## 1. Qué resuelve

El modelo de datos único de "todo lo que se puede vender o insumir": productos
con stock, insumos, servicios, packs de sesiones, combos, descuentos y
giftcards. Es la tabla de la que dependen ventas, compras, producción y
reportes — si el catálogo no distingue bien qué ES cada ítem, cada módulo
tendría que reinventar su propia heurística para saber si algo lleva stock,
si se factura, o si arma una receta al venderse.

## 2. Entidades y datos

| Tabla/columna | Qué guarda | Invariantes / trampas |
|---|---|---|
| `item.itemKind` (mig 15) | Tipo canónico del ítem — 12 valores: `producto`, `insumo_stock`, `insumo_sin_stock`, `insumo_control`, `produccion_directa`, `produccion_previa`, `servicio`, `servicio_sesiones`, `combo_fijo`, `combo_dinamico`, `descuento`, `giftcard`. Fuente única listada en `ItemKind::MAP` (`api/lib/Items/ItemKind.php:27-40`), replicada en `VALID_KINDS` (`api/v1/items.php:141-147`) y en el `CHECK` de BD (`15_item_kind.sql:36-44`). | `NOT NULL` desde mig 15, **sin `DEFAULT` real en producción** (ver regla 1) — cualquier INSERT en `item` que no declare `itemKind` explícitamente rompe la transacción. |
| `item.itemType` / `itemCanSale` / `itemTrackInventory` / `itemProduction` | Los 4 flags LEGACY que antes de mig 15 eran la única fuente de verdad del tipo. Se mantienen en **dual-write** con `itemKind` (`ItemKind::legacyFlags()`) hasta "Slice E", porque el POS y el panel legacy todavía los leen. | `itemKind='pack'` NO existe en este mapa — ver regla 3. `saleExplodesRecipe()` (módulo Stock) decide explosión de receta con estos flags, no con `itemKind` — ver `05-stock.md` / `06-produccion.md`. |
| `item.hasVariants` / `item.variantParentId` / `item.variantAttributes` (mig 99) | Una variante es un `item` completo (stock/costo/precio/SKU propios) enlazado al padre vía `variantParentId`. El padre tiene `hasVariants=true`. | Antes de mig 99 estos tres campos se escribían al JSONB `data` y NUNCA volvían — el `PUT` devolvía 200 pero la columna real no cambiaba (`99_item_variant_fields_promote.sql:1-10`). Ver regla 4. |
| `item.itemIsParent` / `item.itemParentId` | Mecanismo LEGACY de "grupos" (padre-hijos sin atributos de variante) — **jerarquía paralela y distinta** a `variantParentId`/`hasVariants`. | Dos sistemas padre-hijo conviven sobre la misma tabla por razones históricas — `frontend/lib/types/item.ts:128` lo documenta explícito. No confundir un `itemParentId` de "grupo" con un `variantParentId` de variante real. |
| `item.itemMinStock` / `item.itemMaxStock` (mig 133) | Umbrales de quiebre/sobrestock. Detalle de invariantes en `05-stock.md` (regla 8) — solo filtran/ordenan el listado paginado, no hay alerta proactiva confirmada. | `NULL` ≠ `0` — ver `05-stock.md`. |
| `item.data` (JSONB) | Absorbe todo lo que no es columna: `itemUOM`... no, `itemUOM` SÍ es columna real (unidad de medida). Absorbe `itemDescription`, `itemWaste` (merma planificada), `itemComissionPercent`/`Type`, `itemPricePercent`/`Type`, `itemCurrencies`, `itemSessions`, `itemDateHour` (disponibilidad), `itemGiftcardColor`, `packDurationDays`, y `itemBarcode` (sin migración que la promueva a columna). | El SELECT de listado (`buildItemsSelectSql()`, `ItemsQuery.php:140-149`) incluye `i.data` completo — un campo JSONB nuevo aparece en la respuesta sin tocar el SELECT, pero **no** se aplana a nivel raíz salvo que `presentItem()` lo mapee explícitamente (ver `hasAddons`/`tags`/`addonGroups` como los únicos JSONB que sí se procesan). |
| `item.hasAddons` (expuesto, no columna) | Calculado con `EXISTS` correlacionado en cada SELECT del listado — no es un flag persistido. | `ItemsQuery.php` (ver regla de combos/add-ons abajo, doc propio `02-combos-y-addons.md`). |
| `item_compound` (mig 19, canónica desde mig 75) | Receta: `(parentItemId, childItemId, quantity, sort)`. Un combo fijo, para el motor de stock, es una receta más. | `UNIQUE(parentItemId, childItemId)` — sumar cantidad es editar la fila, no insertar otra. `childItemId` es `ON DELETE RESTRICT`. Detalle completo en `06-produccion.md`. |
| `toCompound` (LEGACY, muerta) | Predecesora de `item_compound`. | `CompoundService.php` (su dueño) está `@deprecated`, sin callers activos confirmado por grep (`CompoundService.php:7-10`). No reintroducir — ver regla 5. |

## 3. Reglas de negocio

1. **`itemKind` es `NOT NULL` sin `DEFAULT` efectivo en ninguna BD migrada incrementalmente — el incidente de signup fue real y el fix es a nivel aplicación, no de columna.** `db-schema-postgres.sql:272` sí muestra `DEFAULT 'producto'`, pero ese default se agregó a mano al schema base el 2026-06-11 (commit `5c45e2b4`), **antes** de que existiera la migración 15 (creada 2026-06-29). La mig 15 (`15_item_kind.sql:16-33`) hace `ADD COLUMN itemKind VARCHAR(30)` → backfill → `ALTER COLUMN ... SET NOT NULL`, **sin `DEFAULT`**. Como `api/database/migrate.php` solo carga el schema base en instalaciones nuevas y aplica las migraciones numeradas sobre el resto, toda BD real de producción quedó con la columna `NOT NULL` y sin default. Consecuencia confirmada: el 2026-08-04 (más de un mes después de mig 15) el alta demo de `SignupService` no declaraba `itemKind` en su INSERT, violaba el `NOT NULL`, envenenaba la transacción (25P02) y el signup entero moría (`ItemKind.php:9-15`, `SignupService.php:233-240`). El fix real fue centralizar el mapa kind→flags en `ItemKind::insertRecord()` para que ningún caller nuevo pueda omitir `itemKind` — no un `DEFAULT` de columna.
2. **`itemKind` vive en dual-write con los 4 flags legacy hasta "Slice E".** `ItemKind::legacyFlags()` (`ItemKind.php:48-51`) es la única fuente que traduce kind → flags; `kindToLegacyFlags()` en `items.php:132-138` es un wrapper que delega ahí a propósito, documentando que ANTES vivía como función suelta dentro de `items.php` y otros callers (como `SignupService`) no podían reusarla — la misma raíz del incidente de la regla 1.
3. **HALLAZGO — el kind `pack` existe end-to-end en el frontend pero el backend lo rechaza con 422 en toda alta nueva.** El frontend tiene `"pack"` en el union `ItemKind` (`frontend/lib/types/item.ts:25`), metadata completa (`KIND_META.pack`, `item.ts:441-451`), un campo propio `packDurationDays` en el form (`use-items.ts:594`), y lo ofrece en el selector de "Nuevo item" (itera `ALL_KINDS`). `useCreateItem()` manda `kind: values.kind = "pack"` tal cual al `POST /v1/items` (`use-items.ts:206-207`). Pero `VALID_KINDS` en el backend (`items.php:141-147`) y el `CHECK` de mig 15 **no incluyen `'pack'`** — el create-path explícitamente devuelve `apiError('kind inválido: pack', 422)` cuando `$kind` no está en `VALID_KINDS` (`items.php:763-767`). Ningún código del backend inserta un ítem con `itemType='pack'` (grep exhaustivo sin resultados de escritura, solo lectores como `SaleService.php:2470` y `PackService.php` que asumen que ya existe). Crear un ítem tipo "Pack / Combo de servicios" desde el panel falla siempre con 422 hoy.
4. **Bug histórico confirmado y corregido — mig 99 rescató campos de variantes que se escribían a JSONB y nunca volvían.** `_getTableSchema()` (mapa a mano de ~22 tablas, `api/includes/functions.php`) no listaba `hasVariants`/`variantParentId`/`variantAttributes` entre las columnas reales de `item`, así que el enrutador a JSONB los mandaba a `data` — el `PUT` devolvía 200 pero la columna nunca cambiaba (`99_item_variant_fields_promote.sql:1-10`). El fix NO fue solo la migración de datos: `_getTableSchema()` se **eliminó por completo** y se reemplazó por introspección real contra PG (`Punto\App\Database\Schema::split`, `functions.php:1433-1461`) — arreglo del wrapper compartido, no un parche puntual de esas 3 columnas.
5. **`item_compound` es la fuente canónica de recetas desde mig 75; `toCompound` es legacy muerta, sin callers.** El comentario de la propia mig 75 documenta la dirección real del bug (inversa a lo que suele asumirse): *"el editor [nuevo] escribe `item_compound`, el negocio [venta/COGS/void] lee `toCompound` [vieja] → las recetas editadas no afectaban stock ni costos reales"*, hasta que mig 75 hizo el switch de lectura (`75_recipes_canonical.sql:6-15`). Hoy `Inventory::getCompoundsArray()` (`api/lib/App/Domain/Inventory.php:102-114`) lee exclusivamente de `item_compound`. `CompoundService.php` (dueño de `toCompound`) está `@deprecated` y confirmado sin callers activos (`CompoundService.php:7-10`) — no reintroducir una llamada.
6. **`explodeRecipe()` es recursiva desde el commit `2c81f68c` (2026-08-11), no desde el 2026-08-17.** Firma: `Inventory::explodeRecipe(mixed $itemId, mixed $companyId, float $units, array $visitados = []): array` (`Inventory.php:152-157`). Recorre niveles de receta con guard de ciclos (corta y hace `error_log` si el `itemId` ya está en `$visitados`), multiplica cantidades nivel a nivel y aplica merma planificada (`itemWaste`) en CADA nivel. Antes de este commit, un combo de 3 niveles (combo → roll → insumo) solo descontaba el nivel intermedio que llevaba stock propio y dejaba insumos más profundos sin tocar. El flag que indica "este ítem tiene receta" NO es un booleano en `item` — es la existencia de filas en `item_compound.parentItemId`; el discriminante de si la venta explota la receta es `itemProduction`/`itemTrackInventory` (no `itemKind`) — detalle completo en `06-produccion.md`, no duplicado acá.
7. **`stockOnHand` se expone desde `ItemsQuery.php` pero el POS nunca lo recibe — confirmado vigente.** `frontend/lib/pos-bff/reshape.ts:83` sigue fijando `stock: null` a mano con un TODO desactualizado, pese a que `ItemsQuery.php:147` (`COALESCE(st.onhand, 0) AS stockOnHand`) y `presentItem()` sí lo mapean. Documentado en detalle con evidencia completa en `05-stock.md` regla 7 — se cita acá, no se duplica.
8. **El realm de dispositivo POS (`pos-app`) solo puede LEER el catálogo — cualquier escritura (crear/editar/archivar ítem) devuelve 403.** Guard explícito en `api/v1/items.php:154-165`: una caja comprometida no debe poder modificar el catálogo, solo venderlo. El CRUD completo es exclusivo del realm `panel`.
9. **`item.itemUOM` (unidad de medida) SÍ es columna real**, no JSONB — a diferencia de la mayoría de los campos "descriptivos" del ítem (`db-schema-postgres.sql:279`).

## 4. Flujos principales

**Alta / edición manual (panel)** — `POST`/`PUT /v1/items` (`api/v1/items.php`). Valida `kind` contra `VALID_KINDS` (422 si no matchea, ver regla 3), sincroniza flags legacy vía `kindToLegacyFlags()`, y en `PUT` NO bloquea si el `kind` enviado no matchea el de BD — el Select de kind está deshabilitado en edición del lado del front, así que el backend confía y resincroniza flags en vez de mantener un lock estricto que antes rompía saves legítimos (`items.php:890-899`).

**Generar matriz de variantes** — el frontend arma combinaciones de atributos y llama `POST /v1/items {action:"bulkUpsertVariants", parentId, variants:[...]}` → `VariantService::bulkUpsertVariants()` (`VariantService.php:109-219`). Una única TX: valida que el padre tenga `hasVariants=true` y no sea a su vez una variante (anti-anidamiento, `validateParent()`, `VariantService.php:67-95`), hace INSERT o UPDATE por variante según venga `itemId`, hereda `kind`/tipo/impuesto/outlet/categoría del padre, y da de alta el stock inicial vía `Inventory::manageStock()` con `source='adjustment'` — mismo patrón que la importación masiva.

**Importación CSV** (`ItemImporter.php`, port del legacy `panel/a_items.php?action=importCSV`) — headers `KIND, NOMBRE, SKU, MARCA, CATEGORIA, ETIQUETAS, DESCRIPCION, COSTO, PRECIO, IMPUESTO, SUCURSAL, DESCUENTO_PCT, UOM, MERMA_PCT, COMISION_PCT, STOCK_MINIMO, STOCK_MAXIMO, STOCK_INICIAL`. Acepta el kind por label español o slug (`LEGACY_KIND_LABELS`). `STOCK_INICIAL` solo aplica en altas (requiere `SUCURSAL`) y se registra como movimiento de ajuste vía `Inventory::manageStock()`, nunca como columna directa — mismo patrón que variantes. Tope 2000 filas por subida.

**Edición de receta** — `ItemCompoundService` (`api/lib/Items/ItemCompoundService.php`), CRUD directo sobre `item_compound`. El descuento implícito de un combo fijo (`ComboPricing`) se calcula al vuelo comparando `itemPrice` del padre contra la suma de precios de los hijos, sin columna propia (`comboPricing`, `items.php:681-684`).

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Stock (`05-stock.md`) | `itemTrackInventory` decide si el ítem participa de `manageStock()`. | Que `PosItem.stock` del catálogo llegaría al POS — falso hoy (regla 7). |
| Producción (`06-produccion.md`) | `item_compound` + flags `itemProduction`/`itemTrackInventory` como discriminante de receta, no `itemKind`. | Que el mismo predicado (`saleExplodesRecipe`) se usa en venta y anulación — si divergieran, anular repondría insumos nunca consumidos. |
| Combos y add-ons (`02-combos-y-addons.md`) | `addon_group.itemId` es el ítem "dueño" del grupo (no reusable entre productos); combo fijo usa `item_compound`. | Que `itemKind` (`combo_fijo`/`combo_dinamico`) es solo etiqueta semántica — el propio doc de combos documenta que "hoy casi no significan nada operativamente" a nivel de motor. |
| Impuestos (`04-impuestos.md`) | `item.taxId` referencia `taxonomy`. | NO VERIFICADO en esta sesión — no se exploró en profundidad la resolución de tasa desde el catálogo (queda declarado como hueco, no como hecho). |
| Listas de precio (`03-listas-de-precio.md`) | `itemPrice`/`itemDiscount` como base de resolución. | NO VERIFICADO en esta sesión — mismo hueco que impuestos. |
| POS (bootstrap / `reshape.ts`) | Consume el listado paginado para armar el catálogo local. | Que `row.kind` siempre viene poblado — el reshape es defensivo (`kind: row.kind ?? "producto"`), así que un kind ausente degrada a `producto` en silencio en vez de romper. |
| POS — escritura | Ítem creado/editado exclusivamente desde el realm `panel`. | Que ningún endpoint de catálogo relaje el guard 403 para `pos-app` — una caja comprometida no debe poder alterar el catálogo (regla 8). |
| Compras (`08-compras.md`, no escrito aún) | `item.supplierId` referencia al proveedor preferido. | NO VERIFICADO — no se exploró el flujo de compra en esta sesión. |

## 6. Offline (POS)

El catálogo en sí es panel-only para escritura (regla 8): el dispositivo POS
nunca crea ni edita ítems, solo los lee. Lo que sí importa para el offline-first
del POS es que el catálogo cacheado en el bootstrap sea suficiente para EMITIR
una venta sin conexión — precio, impuesto congelable y receta a explotar tienen
que viajar en ese cache. La brecha conocida es la de `stockOnHand` (regla 7):
el POS no recibe saldo de stock, así que no puede alertar "sin stock" estando
offline ni online — es una limitación de la app, no del transporte offline en
sí.

## 7. Huecos conocidos y NO verificado

- **Kind `pack` no se puede crear vía API** (regla 3) — hallazgo confirmado de esta sesión, contrato roto entre frontend (UI completa) y backend (rechazo 422).
- **`itemBarcode` vive en JSONB sin promover a columna** (mig 99 promovió otros 3 campos de variantes, no este). Se escribe desde `VariantService` en la matriz de variantes. **NO VERIFICADO**: no se encontró evidencia de que algún flujo de escaneo de código de barras en el POS lea este campo — no aparece en `ItemsQuery`'s campos mapeados a nivel raíz ni en el bootstrap del POS. Si existe una función de "buscar por código de barras", no se confirmó que use `itemBarcode`.
- **Catálogo↔Precios y Catálogo↔Impuestos** — no se investigó en profundidad la resolución de precio/tasa desde el catálogo en esta sesión (ver sección 5). Antes de asumir el contrato, leer `03-listas-de-precio.md` y `04-impuestos.md` directamente.
- **Catálogo↔Compras** — no explorado; `item.supplierId` existe como columna pero no se verificó cómo lo usa el flujo de compra.
- **Alerta proactiva de umbrales** (`itemMinStock`/`itemMaxStock`) — mismo hueco que documenta `05-stock.md` regla 8, no se encontró mecanismo fuera del listado paginado.

## 8. Planes y decisiones relacionados

- `context/13-items-refactor-plan.md` — plan abierto del rewrite de Ítems/Servicios (Slice A ✅ commit `b8b0abc8`, Slice B pendiente). Principio rector del owner citado ahí: `itemKind` es la única verdad, sin reglas condicionales por combinación de flags.
- `context/modules/06-produccion.md` — recetas, producción directa/previa, merma. No duplicado acá.
- `context/modules/02-combos-y-addons.md` — combo fijo, combo dinámico, grupos de add-ons. No duplicado acá.
- `context/modules/05-stock.md` — choke point de movimientos de stock, incluida la trampa del `PosItem.stock` nulo (regla 7 de este doc).
