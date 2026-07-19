# Módulo de Órdenes — plan (O0-O4)

Plan cerrado por el owner 2026-07-18. Decisiones abajo NO se relitigan; solo
se ejecutan. Fase actual: **O0 core backend** (branch `orders-o0`).

## Principio de diseño

La orden es una **entidad propia**, separada de `transaction`:

- **Orden** (`pos_order`) = ciclo operativo (tomar pedido → cocina → entrega).
  Vive independiente de si se cobró o no.
- **`transaction`** = hecho fiscal. Se crea SOLO al cobrar, vía el
  `SaleService` existente (contado/crédito/IVA sin duplicar lógica de
  facturación).

**Corte limpio de los types legacy 11/12** (ver `context/15-mesas-module-plan.md`
decisión D3): el modelo nuevo de Órdenes NO usa `transaction` como storage de
la orden en curso. `api/v1/orders.php` / `OrderService.php` legacy (pedidos
online que aceptan/mueven una `transaction` type=12) quedan intactos — son
un dominio distinto (aceptación de pedido online ya facturado/en tránsito),
no se tocan ni se renombran. El reporte legacy de type=12 sigue existiendo
hasta que haya un plan de migración de datos históricos — fuera de alcance
de O0-O4.

## UX — decisión clave del owner

**Todo ocurre dentro de `/pos` reutilizando la UI del carrito.** No hay una
pantalla nueva de "tomar pedido" — el POS es **modal**:

- **Modo venta** (actual, default) — Pagar factura/cobra directo.
- **Modo orden** (nuevo, O1) — el carrito arma la orden, el botón principal
  cambia de "Pagar" a "Ordenar" (envía a cocina, NO cobra).
- **Modo cotización** (ya existe hoy).
- **Modo reserva** (futuro, fuera de alcance de este plan).

El modo se elige desde el menú de Opciones del POS.

**Módulo Mesas (O3)**: el mapa de mesas reemplaza el área de hotkeys del
carrito. Mesa seleccionada + items agregados + botón principal ("Ordenar")
= una orden asociada a esa mesa (`tablesessionid`). Sin mesa seleccionada,
la orden queda "suelta" (mostrador/take-away, `source='counter'`).

**Cobrar una orden = copiar su contenido al carrito en modo venta** y
facturar con el flujo normal de siempre. Cero duplicación de lógica de
facturación — el carrito en modo venta no sabe ni le importa si sus items
vinieron de una orden o se tipearon a mano. Al confirmar el cobro,
`OrderCoreService::markPaid()` marca la orden `closed` con el
`saletransactionid` resultante.

## Estaciones por categorías

Mismo modelo de ruteo que printer bindings (`categoryIds` jsonb array,
matching por intersección — ver `PrinterBindingService`/`binding.ts`):
cada `order_station` declara qué `categoryId`s atiende. Un ítem se resuelve
a estación buscando la primera `order_station` del outlet cuyo
`categoryids` interseque las categorías del ítem (`item_category`, m2m).
`categoryids = []` es comodín (atiende todo) — mismo comportamiento que
`printer_binding`. Sin match → el ítem queda con `stationid = NULL`
("General").

La comanda impresa (Estación de Impresión, módulo planificado — pool de
impresión) y el KDS (O2) comparten esta misma partición por estación: un
ítem ruteado a "Cocina" imprime en la impresora de Cocina Y aparece en la
pantalla KDS de Cocina, con la misma fuente de verdad (`pos_order_item.stationid`).

## Realtime — KDS / display

Reusa el device pairing existente (`device.module` — los módulos KDS/display
ya están reservados) + canal realtime con el mismo patrón del checkout
screen (`{companyId}:checkout:{registerId}` → `wsPublish` en `screens.php`):

- Canal nuevo: `{companyId}:kds:{outletId}`.
- Eventos: `order:new` (create con sendNow=true o send()), `order:item-status`
  (updateItemStatus), `order:status` (updateStatus/markPaid).
- Además, `realtimePublish('order', ...)` para invalidación genérica de
  queryKeys en el panel/POS (patrón TanStack Query ya usado en el resto del
  código).

## Fases

- **O0 — core backend** (esta fase). Mig `79_orders_core.sql`
  (`order_station`, `pos_order`, `pos_order_item`), `OrderCoreService.php`
  + `StationService`, endpoints `orders-core.php` / `order-stations.php`,
  realtime. Sin UI.
- **O1 — POS modal. ✅ DONE (2026-07-18).** Modo orden en `/pos` (botón
  Pagar→Ordenar), envío a cocina, `/pos/ordenes` (listado real reemplazando
  el placeholder), comandas impresas por estación, cobro por volcado al
  carrito. Toggles `ordenEnVenta` / `ordenAImpresion` / `modoSoloOrdenes`
  cableados al comportamiento real.

  **Archivos**: `lib/cart/store.ts` (posMode, orderParentId, loadFromOrder),
  `hooks/use-orders.ts` + `app/api/pos/orders/route.ts` (BFF), `lib/orders/
  print-comandas.ts`, `hooks/use-clear-cart.ts`, `components/register/
  sale-options-drawer.tsx` (toggle modo orden), `components/register/
  cart-panel.tsx` (botón Ordenar), `components/register/pay-dialog.tsx`
  (mark-paid + ordenEnVenta), `app/(pos)/pos/ordenes/page.tsx`,
  `hooks/use-realtime-sync.ts` (entity `order` → `["orders"]`).

  **Decisiones/deviations de esta fase** (no relitigar, documentadas para
  contexto de O2/O3):
  1. **Impresión de comandas reusa el pipeline existente por `categoryId`**,
     NO un segundo mecanismo basado en `pos_order_item.stationid`. La orden
     ya resuelve `stationId` server-side, pero el pipeline de impresión
     (`printSale`/`getBindingsForSale`) parte tickets por `categoryId` de
     ítem — es el único mecanismo de ruteo de impresión que ya usa todo el
     POS (factura/recibo/cotización). Se resuelve el `categoryId` real de
     cada ítem de orden (vía `itemId` → catálogo) y se deja que el pipeline
     existente arme el ticket con `docType="order"`. Un solo lugar de verdad
     para "qué impresora recibe qué ítems" en vez de dos paralelos. Ver
     comentario en `lib/orders/print-comandas.ts`.
  2. **Toggle "Orden" en el menú de Opciones**: NO stub. Setea `posMode`
     directo (no abre dialog). Cuando `posMode==="orden"` aparece un ítem
     companion "Volver a venta" — oculto si `modoSoloOrdenes` está activo
     (el owner pidió explícitamente ocultar la vuelta a venta en ese caso).
  3. **"Activa" en `/pos/ordenes`**: `ACTIVE_ORDER_STATUSES` = todo excepto
     `closed`/`cancelled` (open, sent, in_progress, ready, delivered) —
     espeja `ORDER_TRANSITIONS` de `OrderCoreService.php`.
  4. **`clear()` respeta `modoSoloOrdenes`** vía un hook dedicado
     (`useClearCart`), NO metiendo el flag en el store del carrito — el
     store se mantiene config-agnóstico a propósito (ver comentario en
     `lib/cart/store.ts`). Todo call-site de `clear()` en el flujo de venta
     pasa por este hook (cart-panel Vaciar/Cancelar, pay-dialog success,
     Ordenar exitoso).
  5. **Detalle de orden por card** (`/pos/ordenes`): `list()` no trae ítems
     (ver `OrderCoreService::list()`), así que cada card pide su propio
     detalle vía `useOrder(order.id)` (N+1 liviano — el volumen de órdenes
     activas simultáneas en un turno es bajo; si O2/O3 revela volumen alto,
     se resuelve trayendo ítems en `list()`).
- **O2 — KDS + pantalla mozos**. Pantallas de cocina (device pairing module
  KDS) consumiendo `{companyId}:kds:{outletId}`; pantalla de mozos para ver
  estado de sus órdenes/mesas.
- **O3 — Mesas sobre el core**. Ejecuta `context/15-mesas-module-plan.md`
  como capa espacial encima de O0: `table_session` = agrupador de una o más
  `pos_order` (mesa abierta puede acumular varias rondas de pedidos).
  **F0+F1 del plan de mesas (schema + servicios + config con editor de
  layout) ya está hecho** (branch `mesas-f0`, 2026-07-19; mig 80 —
  `table_sector`/`dining_table`/`table_session`). Falta la operación real
  (abrir mesa → ordenar → cobrar en `/pos`, que es la F2 de mesas) y el
  link operativo `pos_order.tablesessionid` (columna ya nullable desde mig
  79, sin consumidor todavía).
- **O4 — ecommerce + agenda**. `source='ecommerce'` y `source='schedule'`
  ya están contemplados en el CHECK de `pos_order.source` desde O0 para no
  requerir migración de schema cuando lleguen.

## Schema (O0, mig 79)

Ver `api/database/migrations/postgres/79_orders_core.sql`. Resumen:

- `order_station` — estaciones de preparación por outlet, `categoryids jsonb`
  para ruteo.
- `pos_order` — cabecera de orden. `ordernumber` es correlativo por
  `(companyid, outletid, día local)`, calculado en el service con
  `SELECT COALESCE(MAX(ordernumber),0)+1 ... FOR UPDATE` sobre las filas del
  día (lock suave — carrera de alto volumen es un caso raro en O0, sin UI
  aún; si O1 revela contención real se resuelve con una secuencia por
  outlet+día, no bloqueante para O0).
- `pos_order_item` — líneas, snapshot de nombre/precio, estado individual
  con FK CASCADE a `pos_order`.

## Servicio (O0)

`api/lib/Orders/OrderCoreService.php` (namespace `Punto\Api\Orders`),
patrón `ProductionService.php`: métodos con `$companyId` explícito,
`StartTrans`/`HasFailedTrans`/`CompleteTrans` para create/markPaid,
`ncmExecute` para lecturas simples. `StationService` (mismo archivo o
`OrderStationService.php`) para CRUD de estaciones.

## Endpoints (O0)

- `api/v1/orders-core.php` — `apiAuthTenant(['panel','pos-app'])`. GET
  list/find, POST create/send/item-status/status/mark-paid.
- `api/v1/order-stations.php` — CRUD, `apiAuthTenant(['panel'])` para
  escritura, GET también accesible desde `pos-app` (necesita ver estaciones
  para el modo orden en O1).

Nombres nuevos a propósito — NO pisan `api/v1/orders.php` (legacy type=12).
