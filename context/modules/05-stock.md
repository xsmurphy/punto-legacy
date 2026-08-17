# 05 — Stock

> Estado del doc: borrador (verificado contra código leyendo fuente, sin correr nada)
> Responsable de la última verificación: sesión 2026-08-17

## 1. Qué resuelve

Cómo se mueve, se costea y se lee el saldo de inventario de un ítem. Es la
base de todo lo demás: venta, compra, producción, merma, ajuste manual,
conteo físico y transferencia entre sucursales necesitan la MISMA aritmética
de saldo/costo para que el inventario no diverja entre módulos. Sin un único
punto de entrada, cada módulo reinventaría su propia forma de tocar `stock` y
el costeo (COGS) se rompería en cuanto dos caminos calcularan distinto.

## 2. Entidades y datos

| Tabla/columna | Qué guarda | Invariantes / trampas |
|---|---|---|
| `stock` | Ledger de movimientos — una fila por movimiento, `stockCount` CON SIGNO (`'-0.080'`, `'50.000'`). | El saldo es la SUMA de `stockCount` de un `(itemId, outletId)`, NUNCA el `stockOnHand` de la última fila — ese campo es un acumulado cacheado al INSERT que se desincroniza en cuanto entra un movimiento con fecha anterior a la última fila (compra cargada "de ayer"). `Inventory::onHand()` (`api/lib/App/Domain/Inventory.php:443-456`) es la fuente de verdad para CALCULAR; `getItemStock()` (`:404-427`) sirve para LEER el último snapshot. `stockId` es UUID v4 random en PG — no ordenable por tiempo; la recencia la da `stockDate`. |
| `stock.stockOnHandCOGS` | Costo promedio ponderado del saldo DESPUÉS de ese movimiento. | Cada ingreso recalcula el promedio con su propio costo; cada egreso sale al promedio vigente y NO lo altera (`Inventory::manageStock():688-724`). Un saldo ≤0 no aporta base al promedio (no se compró a ningún precio) — evita que un saldo negativo corrompa el costo. |
| `stock.stockSource` | Motivo del movimiento — string libre, no enum en BD. | Valores reales en uso: `sale`, `void`, `purchase`, `purchase-void`, `purchase_credit_note`, `purchase_credit_note-void`, `return`, `production`, `waste`, `adjustment`, `inventory_count`, `transfer`, `transfer-cancel`. Reportes que filtran por este campo asumen que el caller manda el valor correcto — no siempre es cierto (ver regla 4). |
| `item.itemTrackInventory` / `item.itemProduction` | Discriminan si el ítem lleva stock propio o arma su receta al vender. Detalle completo en `06-produccion.md`. | `manageStock()` es no-op (`return false`) si `itemTrackInventory < 1` — vender/ajustar un ítem sin control de stock no genera fila, y eso es intencional (servicios, insumos sin trackeo). |
| `item.itemMinStock` / `item.itemMaxStock` (mig 133) | Umbral de quiebre / sobrestock, `NUMERIC(15,3)`. | `NULL` = no controlado, DISTINTO de `0` (0 en el mínimo significa "avisame al llegar a cero"). `CHECK (itemMaxStock >= itemMinStock)` cuando ambos están definidos — `133_item_stock_thresholds.sql:38-39`. |
| `toLocation.toLocationCount` | Conteo por depósito interno (`location`), best-effort. | Solo se actualiza si `manageStock()` recibe `locationId` — es un contador aparte de `stock`, no reconciliado por `rebuildLedger()` (ese solo toca `stock`). |

## 3. Reglas de negocio

1. **`manageStock()` es el ÚNICO choke point — 27 callers.** (`Inventory::manageStock()`, `api/lib/App/Domain/Inventory.php:631-852`). Qué GARANTIZA: no-op si el ítem no trackea stock (`:658-660`); costeo por promedio ponderado consistente entre todos los callers; auditoría (`sendAuditoria`, `:806-849`, best-effort — un fallo de auditoría no aborta el movimiento); UN evento realtime `item` por request, batched (no uno por movimiento) vía shutdown function (`:766-786`) — "estructuralmente imposible que un caller nuevo se olvide de avisar" (comentario del propio código). Qué NO garantiza: no valida stock suficiente antes de restar (permite saldo negativo — usado a propósito en `transfer-cancel`, ver `07-transferencias.md`); no abre ni cierra transacción — el caller decide el `StartTrans`/`CompleteTrans` que lo envuelve, así que un `manageStock()` exitoso puede terminar revertido si el resto de la TX falla.
2. **El saldo se lee de la MISMA sucursal a la que se escribe** (`:669`, comentario `:662-668`) — antes se leía con `OUTLET_ID` (sucursal de la SESIÓN), y una operación panel→otra sucursal podía pisar el saldo real de esa sucursal si en la sucursal de sesión el ítem no existía. Reparado; ver regla 6 (migs 130/131) para el daño que causó.
3. **Costeo: ingreso recalcula el promedio, egreso sale al vigente sin alterarlo** — `manageStock():688-724`. No usa `divider()`/`Math::divide` a propósito: esa función devuelve 0 si cualquier operando es ≤0, y una compra sobre saldo negativo borraba el costo promedio en silencio en vez de recalcularlo.
4. **Trampa confirmada — `SaleService.php:1842` lee `$sD['type']` del carrito para decidir el `source` de los movimientos de receta explotada.** `$source = (($sD['type'] ?? '') === 'direct_production') ? 'production' : 'sale'` (`SaleService.php:1842`) — pero el POS nunca manda ese campo (`create-sale.ts`), mismo hueco que el predicado real (`saleExplodesRecipe`, ya corregido por `822f8df3`) tenía antes del fix. Consecuencia: **todo movimiento de stock generado al explotar una receta en una venta llega a `stock` con `source='sale'`, nunca `'production'`**, sin importar si el ítem vendido es de producción directa. Es la misma raíz que `06-produccion.md §7` ya documenta para los reportes de producción — este doc la confirma también del lado Venta→Stock, no solo Producción→Stock: cualquier reporte/filtro que espere `stockSource='production'` para explosión de recetas en ventas no va a encontrar nunca esas filas.
5. **Trampa histórica — P0 de producción.** `Inventory::getAllWasteValue()` (`:858-911`) leía `itemWaste` como columna en vez de extraerlo del JSONB `data`; en Postgres eso tira `42703` DENTRO de la transacción de la venta, la aborta (`25P02` en todo lo que sigue), y la venta caía con 500 sin causa visible en el request. Arreglado con un `CASE jsonb_typeof(...)` explícito (comentario `:860-873` documenta el porqué). Cualquier otro reader crudo de un campo "demoted" a JSONB puede reproducir el mismo patrón.
6. **Migs 130/131 repararon el daño del bug de la regla 2.** Mig 130 (`130_stock_onhand_repair.sql`) recalculó `stockOnHand` como la suma real de movimientos — caso real citado en el comentario: un ítem con compra de 50 unidades quedó en saldo `-0.160` en vez de `49.840`. Mig 131 (`131_stock_cogs_rebuild.sql`) reconstruyó `stockOnHandCOGS`/`stockCOGS` completo: el bug encadenado (egreso reseteaba el promedio a 0 cuando el saldo quedaba ≤0, más `divider()` devolviendo 0 sobre saldo negativo) había dejado **335 de 412 filas con costo 0** — los márgenes de esas ventas salieron inflados en reportes hasta la reparación. Ambas son idempotentes y documentan la causa completa en su propio comentario SQL.
7. **`stockOnHand` se calcula sumando `stock`; `PosItem.stock` viene `null` en el POS pese a que el backend lo expone.** HALLAZGO: `frontend/lib/pos-bff/reshape.ts:83` fija `stock: null` a mano con un comentario `// TODO (A6+): ... El LIST de /v1/items no incluye stock` — pero `ItemsQuery::buildItemsSelectSql()` SÍ lo expone, como `stockOnHand` (`COALESCE(st.onhand, 0) AS stockOnHand`, `ItemsQuery.php:147`, mapeado por `presentItem()` en `:58,92`). El TODO quedó desactualizado respecto al código que agregó el campo (mig 133 / umbrales); la interfaz `UpstreamItemRow` de `reshape.ts` (`:22-52`) ni siquiera declara `stockOnHand`. Consecuencia: `PosItem.stock` (`pos-bootstrap.ts:155-162`, documentado como "para mostrar alerta de stock bajo... rellenado por el BFF bootstrap desde el depósito del outlet activo") está muerto en la práctica — el dato existe en el backend y nunca llega al POS.
8. **Umbrales (mig 133) — solo filtran/ordenan el listado de ítems, no se encontró alerta proactiva.** `itemMinStock`/`itemMaxStock` viajan en `ItemsQuery` (`:56-57`) y se usan para "bajo mínimo"/"sobre máximo" en el listado paginado del panel (`frontend/app/(panel)/items/page.tsx`). NO VERIFICADO: no se encontró ningún mecanismo de notificación/badge fuera de ese listado — no hay evidencia de un job que revise umbrales y avise proactivamente.
9. **HALLAZGO — permiso faltante en ajuste manual y conteo físico.** `inventory.stock.adjust` existe en el catálogo (`api/lib/Auth/PermissionCatalog.php:27`) y gatea la navegación del panel (`panel-auth-guard.tsx:76-77`, oculta los links a `/inventory-count` y `/stock-adjustment`) — pero **ni `api/v1/stock_adjustment.php` ni `api/v1/inventory_count.php` llaman `hasPermission()` en ningún punto** (`grep -c hasPermission` → 0 en ambos archivos). Los dos solo exigen `apiAuthTenant(['panel'])`, es decir, cualquier usuario con sesión de panel activa, sin importar su rol. Es la MISMA clase de bug que `f6d13c83` corrigió para `/v1/stock_transfer.php` y `/v1/remisiones.php` ("cualquier usuario del panel podía mover mercadería") — ese fix no tocó estos dos endpoints. Hoy, un usuario del panel sin el rol de ajuste puede ajustar stock o cerrar un conteo llamando la API directamente, aunque el botón esté oculto en la UI.
10. **Legacy muerto, no reintroducir.** `voidSale()` (`api/includes/functions.php:2627-2729`) es el predecesor pre-PSR-4 de `TransactionService::void()` — a diferencia del port vigente, repone insumos SIN pasar por `saleExplodesRecipe()` (siempre explota el compound). Verificado sin callers activos (`grep -rn "voidSale(" api` solo devuelve la definición y el comentario que documenta el port en `TransactionService.php:954`) — no ejecuta hoy, pero reintroducir una llamada a esta función reintroduciría el bug de doble-consumo que `822f8df3` corrigió.

## 4. Flujos principales

**Ajuste manual** (`StockAdjustmentService::create`, `api/lib/services/StockAdjustmentService.php:7-107`) — filtra ítems no stockeables (`skippedItems`, no tira error), un `manageStock()` por ítem dentro de una única TX, `source='adjustment'`. Sin permiso enforced (ver regla 9).

**Conteo físico** (`InventoryCountService`, `api/lib/services/InventoryCountService.php`) — `create()` abre sesión y snapshotea TODOS los ítems trackeables de la sucursal; `setQty`/`bulkSetQty` registran lo contado; `finish()` genera UN movimiento de ajuste por cada diferencia (`type` según signo), `source='inventory_count'` — `countedQty = NULL` al finalizar se trata como "sin diferencia" (decisión conservadora documentada en el docblock de la clase, `:11-14`), no genera movimiento. `cancel()` no revierte nada porque `finish()` es el único paso que mueve stock.

**Venta** — ver regla 4 y `06-produccion.md §4-5`; descuenta receta (si `saleExplodesRecipe`) y el ítem principal, ambos `source='sale'` (por el bug de la regla 4).

**Anulación** (`TransactionService::voidTransaction`) — repone con el MISMO predicado `saleExplodesRecipe()` que usó la venta, `source='void'`, sin `type` explícito (default `'+'` en `manageStock()`).

**Compra / devolución a proveedor** — ver `08-compras.md`/`09-notas-credito-compra.md` (no escritos aún). Resumen de `source`: compra ingresa (`purchase`, `+`), anular compra revierte (`purchase-void`, `-`); nota de crédito de compra egresa (`purchase_credit_note`, `-` — "el proveedor se lleva la mercadería"), anularla repone (`purchase_credit_note-void`, `+`).

**Producción / merma** — ver `06-produccion.md`, mismo choke point (`source` en `{production, waste}`).

**Transferencia / remisión** — ver `07-transferencias.md` / `20-remision.md`.

**Importación de catálogo / alta de variante** — `ItemImporter.php:378` y `VariantService.php:197` cargan stock inicial, ambos `source='adjustment'`, `type='+'`, sin pasar por `StockAdjustmentService` (llaman `Inventory::manageStock()` directo).

## 5. Interacciones con otros módulos

| Módulo | Qué le pide / le da | Contrato (qué asume) |
|---|---|---|
| Venta (`SaleService`) | Descuenta ítem principal y receta explotada, ambos vía `manageStock()`. | Que `saleExplodesRecipe()` es el único predicado válido para decidir si explota — el `source` del movimiento SÍ depende de `$sD['type']` (regla 4), que es un dato distinto y roto. |
| Anulación (`TransactionService`) | Repone stock con el mismo predicado que la venta usó. | Que nunca se reactiva el camino legacy `voidSale()` (regla 10), que no comparte ese predicado. |
| Compras | Ingresa/egresa con el costo real pagado (`stockCOGS`), que `manageStock()` usa para recalcular el promedio. | Que el costo de compra es dato de entrada confiable — `manageStock()` no lo valida, solo lo promedia. |
| Producción (`06-produccion.md`) | Mismo choke point para insumos y terminados; `getComboCOGS`/`getProductionCOGS` leen `stockOnHandCOGS` para costear. | Que `manageStock()` calcula el promedio ponderado correctamente — producción no reimplementa costeo. |
| Combos / add-ons (`02-combos-y-addons.md`) | Cada opción de add-on descuenta como el ítem que es, misma `explodeRecipe`. | Mismo invariante que venta: `manageStock`/`saleExplodesRecipe` es el único camino. |
| Transferencias / Remisión | Egreso+ingreso (transferencia) vía `manageStock()`; remisión NUNCA mueve stock (ver `20-remision.md`). | Que un caller nuevo de remisión no "complete" el modelo agregando un `manageStock()` por su cuenta — es una decisión explícita, no un hueco. |
| Sincronización | El evento realtime `item` vive ÚNICAMENTE en `manageStock()`, batched por request. | Que ningún módulo publica el evento por su cuenta — vivir dentro del choke point es lo que lo garantiza (`context/15`). |
| Reportes | Reportes de producción directa filtran por `stockSource='production'`. | Ese filtro nunca matchea para ventas (regla 4) ni para producción directa real de ventas (`06-produccion.md §7`) — el contrato está roto en ambos lados del mismo síntoma. |
| POS (catálogo) | `PosItem.stock` debería alimentar la alerta de stock bajo. | Falso hoy — el campo siempre es `null` (regla 7), pese a que el backend expone `stockOnHand`. |
| Panel — permisos | `inventory.stock.adjust` debería ser la única puerta para ajustar/contar. | Falso hoy — solo gatea la UI, no el endpoint (regla 9, hallazgo de esta sesión). |

## 6. Offline

Este módulo no expone un endpoint propio en el realm de dispositivo POS:
ajuste manual, conteo y transferencia son `apiAuthTenant(['panel'])`
exclusivamente. La venta SÍ mueve stock, pero lo hace en el servidor al
sincronizar — el dispositivo nunca calcula ni aplica el movimiento
localmente; solo encola la venta (ver `08-convenciones-criticas.md §53` y,
cuando exista, `22-sincronizacion.md`). `PosItem.stock` (regla 7) es la única
superficie de stock que llega al dispositivo, y está rota.

## 7. Huecos conocidos y NO verificado

- **Permiso faltante en `stock_adjustment.php`/`inventory_count.php`** (regla 9) — hallazgo confirmado de esta sesión, mismo patrón que `f6d13c83` corrigió en transferencia/remisión pero no tocó acá.
- **`PosItem.stock` muerto** (regla 7) — hallazgo confirmado; el backend expone el dato, el reshape del BFF no lo consume.
- **NO VERIFICADO**: si existe alguna alerta proactiva (notificación, badge, job) sobre `itemMinStock`/`itemMaxStock` más allá del filtro/orden del listado paginado de ítems.
- **NO VERIFICADO**: comportamiento de `toLocation.toLocationCount` ante un movimiento backdateado — `rebuildLedger()` solo recalcula `stock`, no hay evidencia de que `toLocation` se reconcilie igual.
- **Legacy `voidSale()`** (regla 10) — confirmado sin callers activos hoy; no se investigó si algún script de mantenimiento o migración de datos aislada lo invoca fuera de las rutas HTTP.

## 8. Planes y decisiones relacionados

- `context/37-numeracion-documentos.md` — numeración correlativa de documentos internos de stock (mig 129), origen del scope OUTLET usado también por transferencia/remisión.
- `context/23-production-module-plan.md` / `06-produccion.md` — modelo de stock de ítems con receta, mismo choke point.
- `context/15-realtime-sync-plan.md` — modelo de batching del evento `item` que vive en `manageStock()`.
