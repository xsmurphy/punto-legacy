# 15 — Plan: módulo de gestión de espacios

> **Creado:** 2026-06-15. **Estado:** F0+F1 (schema + servicios + config con
> editor de layout) hechas 2026-07-19 en branch `mesas-f0`. **F2 (operación
> en el POS)** hecha 2026-07-19 en branch `mesas-f2`, sobre el core de
> Órdenes O0/O1/O2 (`context/24-orders-module-plan.md`). Reemplaza el módulo de
> "espacios" legacy (`ncmSpaces`), que es un grid numérico fijo sin entidad de
> mesa real. Objetivo: gestión de espacios de nivel restaurante/multi-rubro
> (sectores, mozos, reservas, comensales, split de cuentas, estados).
> Pendiente: split de cuenta (F3) y reservas (F4).
>
> **Rename mesas→espacios (2026-07-19, mig 81/82):** el módulo se generalizó
> a "Espacios" — un ESPACIO físico con sesión abierta al que se le asocian
> órdenes hasta cobrar (mesas en gastronomía, sillas de atención en
> peluquerías, habitaciones en hostales/hoteles). El resto de este documento
> describe el diseño F0-F2 tal como se construyó, con la terminología de
> ESE momento (`dining_table`, `table_session`, `tableSessionId`,
> `/pos/mesas`, etc.) — **no se reescribió retroactivamente**. Mapeo a los
> nombres actuales:
>
> | Entonces | Ahora |
> |---|---|
> | `table_sector` / `SectorService` | `space_sector` / `SpaceSectorService` |
> | `dining_table` / `DiningTableService` | `space` / `SpaceService` |
> | `table_session` / `TableSessionService` | `space_session` / `SpaceSessionService` |
> | `pos_order.tablesessionid` / `tableSessionId` | `pos_order.spacesessionid` / `spaceSessionId` |
> | `api/v1/dining-tables.php`, `table-sectors.php`, `table-sessions.php` | `spaces.php`, `space-sectors.php`, `space-sessions.php` |
> | `/pos/mesas`, `/settings/tables` | `/pos/espacios`, `/settings/espacios` |
> | `Punto\Api\Tables\*` | `Punto\Api\Spaces\*` |
>
> Mig 82 (2026-07-19) además agregó la invariante `space.sectorid NOT NULL`
> — todo espacio siempre pertenece a un sector.

## F2 — operación en el POS (hecho 2026-07-19)

UX cerrada por el owner: al seleccionar el módulo Mesas, el mapa de mesas
ocupa el slot de hotkeys (`/pos/mesas`, misma composición mapa+carrito que
`/pos`); el carrito es el mismo `CartPanel` persistente del layout, ahora
"en modo mesa" cuando hay una `tableSessionId` seleccionada.

- **Backend**: `OrderCoreService::create()` acepta `tableSessionId` — valida
  que la sesión sea del mismo tenant+outlet y esté `status='open'` (rechaza
  `bill_requested`/`closed`/`cancelled`), fuerza `source='table'` (manda
  sobre el `source` recibido) y persiste `pos_order.tablesessionid`.
  `list()` ganó el filtro `tableSessionId` (usado por "Cobrar la mesa" para
  traer las órdenes de una sesión). Sin migración nueva — la columna
  `pos_order.tablesessionid` ya existía desde mig 79 (O0), sin consumidor
  hasta ahora.
- **Frontend — BFF nuevos** (mismo patrón Bearer-del-device que
  `/api/pos/orders`): `/api/pos/tables` → `dining-tables.php`,
  `/api/pos/table-sessions` → `table-sessions.php`, `/api/pos/table-sectors`
  (solo GET) → `table-sectors.php`. Hooks dedicados
  `hooks/use-pos-tables.ts` (posFetch, realm pos-app) — **distintos** de
  `use-dining-tables.ts`/`use-table-sectors.ts` (cookie `_jwt_panel`, config
  del panel en `/settings/tables`); mismas entidades, dos superficies de auth.
- **`/pos/mesas`**: sectores en tabs, plano con dos modos de render — canvas
  absoluto 900×600 (mismo tamaño que `layout-editor.tsx`) escalado
  responsive por `transform:scale()` vía `ResizeObserver` cuando el sector
  tiene layout custom, o grilla CSS fallback si no. Colores por estado
  (`PosTableTile`): libre=neutro, ocupada=`primary`, pagando=`amber-500`
  (mismo tono que el chip de sync pendiente en `cart-panel.tsx`),
  deshabilitada=`muted` no clickeable. Badge con nº de órdenes activas +
  tiempo transcurrido (`useElapsed`, mismo hook del KDS/O2).
  - Tap mesa libre → `OpenTableDialog` (comensales opcional) → abre sesión →
    `cartStore.setSelectedTable(sessionId, tableName)` (fuerza `posMode=
    "orden"`) → navega a `/pos`.
  - Tap mesa ocupada/pagando → `TableSessionSheet`: lista de órdenes de la
    sesión (todas, cualquier status) + Agregar orden (mismo `setSelectedTable`
    + navegar a `/pos`, reusando la sesión existente) + Pedir cuenta
    (`requestBill`) + Cobrar + Cancelar sesión (deshabilitado si hay órdenes
    activas — el backend ya lo valida, el botón solo refleja el guard).
- **Ordenar con mesa seleccionada**: `cart-panel.tsx::handleOrderClick`
  incluye `tableSessionId` en el payload de `createOrder`. Al éxito,
  `clearCart()` (que ya resetea `tableSessionId`/`tableName` vía
  `initialState`) + `router.push("/pos/mesas")` — vuelve al mapa con la
  selección limpia, la mesa queda ocupada con su orden nueva.
- **Cobrar la mesa**: nueva acción del cart store `loadFromSession(sessionId,
  tableName, orders)` — merge de líneas de TODAS las órdenes no
  `closed`/`cancelled` de la sesión (mismo criterio de merge que `addLines`,
  pero comparando itemId+nota para no mezclar rondas/notas distintas en una
  sola línea), en modo venta, seteando `sessionParentId` + `sessionOrderIds`
  (paralelo a `orderParentId` pero para N órdenes). El handler de "Cobrar"
  en `/pos/mesas` resuelve primero qué órdenes son billable
  (`fetchOrdersBySession`, filtra client-side status), pide el detalle
  completo de cada una (`fetchOrderDetail`, en paralelo) y arma el merge.
  `pay-dialog.tsx`, al confirmar el pago: si `sessionParentId` está seteado,
  llama `markOrderPaid` por cada `sessionOrderIds` y luego
  `TableSessionService::close(sessionId, transactionId)` — la mesa vuelve a
  `free`. Es una rama nueva, mutuamente excluyente con `orderParentId` (NO
  se reusa ese mecanismo — perdería el resto de las órdenes de la sesión).
- **Realtime**: entidad `table` agregada a `ENTITY_TO_QUERY_KEYS`
  (`hooks/use-realtime-sync.ts`) — invalida tanto el plano operativo del POS
  como la config del panel. Ya estaba publicado desde F0+F1
  (`realtimePublish('table', ...)` en `DiningTableService`/
  `TableSessionService`), sin consumidor hasta ahora.
- **code-reviewer**: sin P0 (aislamiento outlet/tenant de `tableSessionId`
  correcto, forzado server-side). P1 corregido: TOCTOU entre el check de
  `table_session.status='open'` y el INSERT de la orden — el pre-check
  original leía fuera de la TX; ahora se repite con `SELECT ... FOR UPDATE`
  dentro de la TX de `create()` (bloquea `request-bill`/`cancel`/`close`
  concurrentes sobre la misma fila hasta el commit).
- **Fuera de alcance de F2** (queda para fases siguientes): split de cuenta
  por producto/partes iguales/monto entregado (F3, `table_settlement`),
  reservas (F4), asignación de mozos a sector/mesa (`table_assignment`).

## F3 — split de cuenta: requisitos del owner (charla 2026-07-19, plan pendiente)

Tres modos de cobro parcial sobre una sesión de espacio, elegibles por el
mozo/cajero al cobrar:

1. **Por ítems**: seleccionar qué ítems (de las órdenes de la sesión) paga
   cada persona — cobros sucesivos hasta agotar los ítems.
2. **Monto libre (adelanto)**: añadir un pago de X Gs. a cuenta de la mesa,
   sin asociarlo a ítems.
3. **Partes iguales**: dividir el total en N partes y cobrarlas por separado.

Prerequisito de UI (hecho 2026-07-19): el diálogo de sesión muestra los
ítems de cada orden — el cajero necesita verlos para poder seleccionarlos.

### Plan técnico (cerrado 2026-07-19)

#### El problema real: cobrar dos veces lo mismo

Hoy cobrar una mesa es atómico: `loadFromSession` → carrito → una
`transaction` → `markPaid` de TODAS las órdenes → `close` de la sesión. Con
split hay **N cobros contra una misma sesión**, y ahí aparecen los dos
errores que cuestan plata:

- **Doble cobro**: dos mozos cobrando la misma mesa a la vez, o alguien
  seleccionando ítems que otro ya cobró.
- **Cobro incompleto**: la sesión se cierra con saldo pendiente.

Ninguno se resuelve con cuidado en la UI: se resuelven en el modelo.

#### Modelo: ledger de pagos + ítems marcados

**`space_session_payment`** — un renglón por cobro parcial:

```sql
sessionid, transactionid, amount, kind ('items'|'amount'|'share'),
sharecount (solo kind='share'), created_at, companyid, outletid
```

**`pos_order_item.settledpaymentid`** (nullable) — qué pago se llevó ese
ítem. Solo lo usa `kind='items'`.

**Saldo = total de la sesión − Σ pagos.** La sesión solo puede cerrarse con
saldo ≤ 0.

Los tres modos caen en el mismo modelo:

| Modo | `amount` | Marca ítems |
|---|---|---|
| Por ítems | suma de los ítems elegidos | sí (CAS sobre `settledpaymentid IS NULL`) |
| Monto libre | lo que ingresa el operador | no |
| Partes iguales | `total / N` | no |

**El CAS es lo que hace imposible el doble cobro por ítems**: marcar un ítem
ya marcado no afecta filas → se aborta la transacción entera. No es una
validación previa que se pueda ganar por carrera.

#### Redondeo de las partes iguales

`100.000 / 3 = 33.333,33` → en PYG (0 decimales) tres partes de `33.333`
suman `99.999` y **falta 1 Gs**. La última parte absorbe el resto. Explícito
en el service, no emergente: es un clásico generador de descuadres de caja.

#### Fiscal

**Cada cobro parcial es su propia `transaction` → su propio comprobante.**
Es lo correcto: cada comensal se lleva su factura. Reusa `SaleService`
íntegro, sin lógica de facturación nueva.

#### Interacción con lo existente

- `markPaid` de las órdenes y `close` de la sesión ocurren **solo en el
  cobro que lleva el saldo a 0**, no en cada parcial.
- Si la mesa pide más después de un pago parcial, el saldo sube — ya
  contemplado (`bill_requested → open` al agregar orden).
- **Fuera de alcance**: descuentos a nivel sesión combinados con split
  (repartir un descuento entre pagos parciales abre una discusión fiscal
  propia); propina.

#### Fases

- **F3a** — ledger + saldo + modo "monto libre" (el más simple, valida el
  modelo end-to-end).
- **F3b** — modo "partes iguales" (suma el redondeo).
- **F3c** — modo "por ítems" (suma el CAS y la UI de selección).

## F0+F1 — hecho (2026-07-19)

**Desviación clave del schema original de este doc**: las tablas nuevas
(`table_sector`, `dining_table`, `table_session`) usan **lowercase sin
comillas** (patrón migs 72/76/79 — `sectorid`, `companyid`, `outletid`, ...),
NO el camelCase (`sectorId`, `companyId`) descripto en §2 más abajo. §2
queda como referencia conceptual de las columnas/relaciones; el schema real
está en `api/database/migrations/postgres/80_mesas_module.sql`.

- **Mig 80**: `table_sector` (zonas), `dining_table` (mesa: `posx/posy/
  width/height` NULL = sin layout custom → grilla numerada default;
  `shape` incluye `square|round|rect` como mesas reales y `bar|decor_wall|
  decor_plant` como bloques decorativos del editor), `table_session`
  (ocupación — índice único parcial `uq_table_session_active_per_table`
  garantiza una sola sesión `open|bill_requested` por mesa).
- **Backend** `api/lib/Tables/{SectorService,DiningTableService,
  TableSessionService}.php` (namespace `Punto\Api\Tables`, patrón
  `OrderCoreService.php`). `DiningTableService::listWithState()` deriva el
  estado (nunca editable a mano): `disabled` (status=0) → `bill_requested`
  (sesión) → `occupied` (sesión open) → `free`. Reservas (F4) sumarán
  `reserved` entre `free` y `occupied`. `saveLayout()` es batch atómico
  (TX) scopeado a companyId+outletId. Legacy `api/lib/services/
  TableService.php` marcado `@deprecated`, sin tocar (compat con front
  legacy `ncmSpaces`).
- **Endpoints**: `api/v1/table-sectors.php`, `api/v1/dining-tables.php`
  (incluye `?action=bulk` y `?action=layout`), `api/v1/table-sessions.php`
  (`open`/`request-bill`/`cancel`/`close` — **cierre sin cobro real, F2 lo
  conecta a facturación**). Realm `['panel','pos-app']`, outlet scope del
  device para pos-app (mismo patrón `orders-core.php`).
- **Config UI** `frontend/app/(panel)/settings/tables/page.tsx` →
  `TablesManager`: CRUD de sectores inline (`sectors-panel.tsx`), alta
  rápida "crear N mesas numeradas", edición individual, y
  **`components/tables/layout-editor.tsx`** — canvas por sector con
  drag+resize vía `react-rnd` (mismo patrón técnico que
  `components/print-templates/template-editor.tsx`, único precedente
  in-repo de canvas absoluto; sin librerías nuevas). Decisiones del editor:
  - **Unidades del canvas: píxeles crudos 1:1**, no mm — no hay papel físico
    de referencia como en impresión. Grid snap de 10px.
  - Rotación en pasos de 45° (botón, no drag de ángulo libre).
  - Toggle "Layout / Grilla (preview POS)": la vista grilla es read-only y
    muestra el fallback numerado que ve el POS cuando la mesa NO tiene
    posición custom.
  - Bloques decorativos (barra/pared/planta) son filas `dining_table` con
    `seats=0` y shape decorativo — mismo modelo de entidad, sin tabla
    aparte.
- **code-reviewer**: sin P0. P1 corregido (`saveLayout` valida `is_array`
  por posición antes de abrir la TX, en vez de fallar silenciosamente el
  batch). P1 quedó anotado como brecha preexistente (no nueva de este PR):
  panel sin restricción de outlet-scope al crear/mover mesas — mismo gap
  que `orders-core.php`, fuera de alcance de F0+F1.
- **Fuera de alcance de F0+F1** (queda para fases siguientes, ver §10):
  reservas (F4), `table_assignment` (mozos↔sector), `table_settlement`
  (dividir cuenta/cobro — F3), y la operación real (abrir mesa → ordenar →
  cobrar) en `/pos`, que es **F2** y vive en `app/(pos)/pos` — NO se tocó
  en esta fase (otro agente trabaja en paralelo sobre `screens.php`/
  `orders-core.php`/`app/(screen)/**`, fuera del alcance de esta sesión).

---

## 0. TL;DR

El módulo actual es mínimo: una "mesa" es un `transaction type=11` con
`transactionName`=número, grid fijo 1..`tablesCount`, sin layout, sin sectores,
sin reservas, sin comensales, sin split, y cerrar mesa = `DELETE` (sin historial).

El plan introduce **entidades reales** (`sector`, `dining_table`,
`table_session`, `reservation`, `table_assignment`, `table_split`) y servicios
dedicados, manteniendo compat con el flujo `type=11/12` durante la transición.

**Alcance/orden (decidido 2026-06-15 — ver §9):**
1. **Backend + schema primero** — base durable.
2. **Config (definir sectores/mesas/layout) en frontend** — ya es React/shadcn.
   Puede avanzar independiente.
3. **Operación (abrir/ordenar/cobrar) en `app-next`** (D1) — el nuevo POS React,
   NO el `/app` legacy en Alpine. → **depende de que `app-next` arranque**
   (`context/14`). El módulo NO se construye en el legacy.
4. **Online-only** (D2): el módulo de mesas requiere conexión. NO funciona
   offline. El offline se reserva EXCLUSIVAMENTE para ventas simples + creación
   de clientes. Esto elimina toda la complejidad de lease/concurrencia de mesas.
5. **Corte limpio** (D3): se rehace completo, se elimina el `type=11/12`. Sin
   dual-run ni backfill (el POS no está en producción real).

---

## 1. Estado actual (lo que se reemplaza)

| Aspecto | Hoy |
|---|---|
| Entidad mesa | NO existe — `transaction type=11`, `transactionName`=nº |
| Órdenes | `transaction type=12`, mismo `transactionName`; ítems en `itemSold` + `meta.transactionDetails` |
| Mapa | grid fijo 1..`tablesCount` (default 100), string-concat + jQuery DOM, sin x/y |
| Estados | `transactionStatus` 1=libre/activa, 2=reservada (solo 3 visuales: libre/ocupada/reservada) |
| Sectores | NO existe |
| Comensales | NO existe (`guests/covers/pax`) |
| Mozo | `transaction.userId` (sin relación mozo↔mesa/sector) |
| Reservas | NO existe (status=2 ad-hoc sobre la mesa) |
| Unir mesas | `transactionParentId`; `joined` degradado a bool (rompe badge n/m) |
| Split cuenta | NO existe — pagos = array JSON en `transactionPaymentType` |
| Historial | NO — cerrar mesa = `DELETE` de la fila type=11 |
| Backend | `TableService` (rename/unreserve/assignUser/listTables/closeTable/joinSpaces/moveOrders), `OrderService` (algunos métodos solo scope `outletId` — bug) |
| Endpoints | `api/v1/tables.php`, `api/v1/orders.php` |
| Front | `ncmSpaces` (`app.js:1329-1519`), rutas `tables_module`/`viewTable`, gated por `settings.storeTables` |

---

## 2. Modelo de datos nuevo

> Convenciones (respetar): PK UUID v7 vía `ncmInsert`; `companyId UUID NOT NULL`
> + `outletId UUID` en toda tabla operativa; JSONB demote (`data` para
> descriptivo); registrar cada tabla nueva en `_getTableSchema()` whitelist;
> soft-delete por `status`; nunca `ORDER BY <x>Id` (usar timestamps).
> ⚠️ `table` es palabra reservada en SQL → la entidad mesa se llama `dining_table`.

### 2.1 `sector` — zonas (Terraza, Salón, Barra)
```
sectorId UUID PK, companyId NOT NULL→company, outletId→outlet,
name VARCHAR, sortOrder INT, color VARCHAR, status SMALLINT (1 activo),
data JSONB,  created_at, updated_at
```

### 2.2 `dining_table` — la mesa como entidad de primer nivel
```
tableId UUID PK, companyId NOT NULL, outletId→outlet, sectorId→sector (nullable),
name VARCHAR (nº o nombre), capacity SMALLINT (puestos),
posX INT, posY INT, shape VARCHAR (round|square|rect),  -- floor plan
status SMALLINT,  -- ver §3 (free/occupied/reserved/bill/cleaning/disabled)
data JSONB (merge con vecinas, notas físicas), created_at, updated_at
UNIQUE(companyId, outletId, name)
```
Reemplaza el grid numérico fijo: las mesas se definen explícitamente, con
posición para el plano de salón.

### 2.3 `table_session` — instancia de ocupación (reemplaza el type=11 efímero)
```
sessionId UUID PK, companyId NOT NULL, outletId,
tableId→dining_table, waiterId→contact (mozo, type=0),
covers SMALLINT (comensales), status SMALLINT (open|bill_requested|closed),
serviceCharge NUMERIC (cargo de servicio, opcional, % o monto),  -- D5
splitFromSessionId→table_session (nullable, si nació de dividir otra mesa),
openedAt, closedAt, mergedIntoSessionId→table_session (uniones),
data JSONB (notas, origen reserva), created_at, updated_at
```
Da **historial** (cerrar = `status=closed` + `closedAt`, no DELETE) y permite
analítica (rotación de mesa, tiempo promedio, ventas por mozo/sector).
Las órdenes (`transaction type=12`) ganan FK **`tableSessionId`** (columna real
para JOINs). Los ítems (`itemSold`) son **movibles entre sesiones** (para dividir
mesa — §4). La propina se modela por liquidación (§2.6), no a nivel sesión.

### 2.4 `reservation` — reservas
```
reservationId UUID PK, companyId NOT NULL, outletId,
tableId→dining_table (nullable hasta asignar), sectorId→sector (preferencia),
customerId→contact (nullable), customerName VARCHAR, customerPhone VARCHAR (E.164),
partySize SMALLINT, reservedAt TIMESTAMPTZ, durationMin INT,
status SMALLINT (pending|confirmed|seated|no_show|cancelled),
sessionId→table_session (al sentar, link a la ocupación),
notes TEXT/data JSONB, created_at, updated_at
```

### 2.5 `table_assignment` — mozos a sectores/mesas (turno/servicio)
```
assignmentId UUID PK, companyId NOT NULL, outletId,
waiterId→contact, sectorId→sector (nullable), tableId→dining_table (nullable),
activeFrom TIMESTAMPTZ, activeTo TIMESTAMPTZ (nullable=turno abierto),
data JSONB, created_at
```
Permite "este mozo atiende Terraza" o mesas puntuales. Opción de producto:
gatear la vista operativa para que el mozo solo vea sus mesas asignadas.

### 2.6 `table_settlement` — liquidaciones / división de cuenta (incl. pagos parciales)
```
settlementId UUID PK, companyId NOT NULL, outletId, sessionId→table_session,
mode VARCHAR (equal|by_item|by_amount|partial),
amount NUMERIC (lo que paga esta parte), tipAmount NUMERIC (propina, D5),
customerId→contact (nullable — a nombre de quién va la factura),
paymentType TEXT (JSON de métodos de pago de esta parte),
invoiceTransactionId→transaction (la venta/comprobante que generó esta parte),
status VARCHAR (paid), data JSONB (ítems asignados si by_item, nº de partes si equal),
created_at
```
Una **liquidación** es una parte de la cuenta que ya se cobró. La sesión acumula
liquidaciones hasta cubrir el total → recién ahí `status=closed`. Cubre los 4
casos que pidió el owner:

- **equal** (partes iguales): total ÷ N comensales; cada parte = una liquidación.
- **by_item** (por producto): se seleccionan ítems consumidos por cada persona;
  cada conjunto = una liquidación.
- **by_amount / partial** (monto entregado): una persona se retira y entrega
  100.000 Gs → se registra como liquidación parcial; queda como uno de los pagos
  de la mesa. La mesa sigue abierta con el saldo restante; al cerrar, las
  liquidaciones suman el total.

**Cada liquidación produce su propio `transaction`** (venta type 0/3) con su
`customerId` y su `invoiceNo` → **cada persona puede pedir su factura a su nombre**
(ver §8). `invoiceTransactionId` linkea la liquidación a ese comprobante.
La propina viaja por liquidación (cada quien deja su propina).

### 2.7 Cambios en tablas existentes
- `transaction`: nueva columna **`tableSessionId UUID`** (FK, nullable) — liga
  órdenes/ventas a la sesión. Mantener `transactionName` durante la transición.
- Registrar `sector/dining_table/table_session/reservation/table_assignment/table_split`
  en `_getTableSchema()` (routing JSONB) o el flatten pisará los JSONB.

---

## 3. Modelo de estados de mesa

```
disabled ─┐
free ──→ reserved ──→ occupied ──→ bill_requested ──→ (pagada) ──→ free
  └────────────────────→ occupied (walk-in, sin reserva)
```
- **free**: disponible.
- **reserved**: tiene reserva futura próxima (badge con hora).
- **occupied**: sesión abierta (muestra covers, mozo, tiempo transcurrido, total parcial).
- **bill_requested**: pidió la cuenta (señal al mozo/caja).
- **disabled**: fuera de servicio.
Sin estado "limpieza" (D4): al pagarse completa, la mesa vuelve directo a **free**.
Color + badges en el plano. Transiciones con permiso donde aplique (reabrir,
anular). Estados de **orden** (KDS) son aparte: pending/in_prep/ready/served.

---

## 4. Backend — servicios y endpoints (en `/api`, namespaced `Punto\Api\Tables`)

| Service | Responsabilidad |
|---|---|
| `SectorService` | CRUD sectores, orden, color |
| `TableService` (reescritura) | CRUD mesas, posición en plano, capacidad, status, habilitar/deshabilitar |
| `TableSessionService` | `open(tableId, covers, waiterId)`, `addOrder`, `getActive(outletId)`, `requestBill`, `close`, `merge`(unir), `moveOrders`(mover a otra mesa existente), **`splitSession(sessionId, itemIds, targetTableId, covers)`** (dividir mesa: mueve los `itemSold` seleccionados a una sesión NUEVA en otra mesa, con su cuenta aparte), historial |
| `ReservationService` | CRUD, disponibilidad por franja, `assignTable`, `seat`(→sesión), `noShow`, `cancel` |
| `WaiterAssignmentService` | asignar/listar mozos por sector/mesa, turno |
| `SettlementService` | división de cuenta + cobro por partes. `settleEqual(sessionId, n, partIndex, customerId?, tip?)`, `settleByItem(sessionId, itemIds, customerId?, tip?)`, `settleByAmount(sessionId, amount, customerId?, tip?)`. Cada llamada **emite un `transaction` venta** (con número de comprobante atómico + `customerId` para la factura) y registra el pago; cuando la suma de liquidaciones cubre el total, cierra la sesión |

**Endpoints** (REST, `apiAuthTenant(['panel','pos-app'])` para los compartidos):
`/v1/sectors`, `/v1/tables`, `/v1/table-sessions`, `/v1/reservations`,
`/v1/table-assignments`, `/v1/table-splits`. Listado operativo del plano:
`GET /v1/tables?resource=floor&outletId=` → mesas + sesión activa + covers +
mozo + total parcial + estado, en una sola respuesta.

**Fix de deuda:** los métodos de `OrderService` que filtran solo por `outletId`
(`queryOrderRows`, `getTableClose`, `getTableDetail`) deben scopear también por
`companyId` (aislamiento multi-tenant) al portarse.

---

## 5. Frontend

### 5.1 Config (en frontend — React/shadcn)
- **Editor de salón/plano**: definir sectores, crear mesas con capacidad, forma y
  posición (drag-drop en canvas, como el editor de plantillas de impresión ya
  existente). Asignación de mozos a sectores.
- CRUD de sectores y mesas, reglas de reserva (franjas, duración default).

### 5.2 Operación (en `app-next` — React/shadcn; depende de que arranque el rewrite)
1. **Plano de mesas** — tabs por sector (Terraza/Salón/Barra), cada mesa con
   color por estado + covers + mozo + tiempo + total parcial; refresh en vivo (WS).
2. **Abrir mesa** — comensales (covers), mozo (default = usuario actual), origen
   (walk-in / desde reserva).
3. **Detalle / orden** — agregar ítems, modificadores, notas de cocina, enviar a
   KDS por tiempos/curso (entrada/principal/postre = "firing"), total corriente.
4. **Reservas** — lista/calendario del día, crear, asignar mesa, sentar
   (convierte reserva→sesión), no-show.
5. **Dividir mesa (mover ítems a otra mesa)** — seleccionar comensales/productos
   y mudarlos a una mesa nueva con cuenta aparte (ej. 2 de 6 se mudan). Usa
   `splitSession`: los ítems elegidos pasan a una sesión nueva en la mesa destino.
6. **Dividir la cuenta (cobrar por partes)** — 4 modos:
   - **partes iguales**: total ÷ N comensales.
   - **por producto**: arrastrar/seleccionar los ítems de cada persona.
   - **por monto entregado**: una persona entrega X (ej. 100.000 Gs), se cobra
     esa parte y la mesa sigue abierta con el saldo (pago parcial).
   - cada parte cobra independiente, con **propina** propia y **factura a su
     nombre** (customer propio) → ver §8.
7. **Acciones de mesa** — unir, mover orden, transferir de mozo, pedir cuenta,
   reabrir (con permiso), descuento/anulación (con permiso), propina/cargo de
   servicio.

---

## 6. Tiempo real (WebSocket)

Reusa `ncm-ws` + Redis pub/sub (`ws_publish.php`). Canal por outlet, eventos:
- `table:status` — cambia color en el plano de todos los dispositivos.
- `order:new`/`order:ready` — KDS y aviso al mozo.
- `table:bill_requested` — aviso a caja.
- `reservation:new` — refresca lista de reservas.
Esencial para multi-mozo (varios dispositivos sobre el mismo salón). No bloquea
el cobro (best-effort, como hoy).

---

## 7. Online-only (decisión D2)

**El módulo de mesas NO funciona offline.** Requiere conexión para toda
operación (abrir mesa, ordenar, cambiar estado, split, reservas). Regla de
producto del owner: **el offline se reserva EXCLUSIVAMENTE para ventas simples +
creación de clientes; nada más.**

Consecuencias (todas simplificadoras):
- **Sin lease ni reconciliación de mesas.** El servidor es la única fuente de
  verdad del estado de mesa; no hay conflicto offline que resolver. (El lease de
  `context/14 §9` aplica a la numeración de ventas simples offline, NO a mesas.)
- **Estado de mesa en tiempo real** vía WS + refetch — al ser online, todos los
  dispositivos ven el mismo estado del servidor sin cola optimista.
- **Split + numeración**: como es online, cada parte toma su número de comprobante
  **server-side atómico** (`UPDATE register SET registerInvoiceNumber = +1
  RETURNING`), sin reserva de rango ni lease — secuencial perfecto, sin huecos.
- **Sectores/mesas/sesiones**: se leen del servidor (TanStack Query en app-next),
  no del store offline en memoria. Si no hay conexión, el módulo muestra estado
  "sin conexión" y bloquea operación (no encola).
- **Degradación**: si la caja pierde internet a mitad de servicio, puede seguir
  haciendo **ventas simples** offline (cobro directo sin mesa); las funciones de
  mesa quedan inhabilitadas hasta reconectar.

---

## 8. Multi-tenant, permisos, fiscal

- `companyId` + `outletId` en toda query (corregir el scope `outletId`-only de
  OrderService).
- Permisos nuevos: ver-todas-las-mesas vs solo-asignadas, reabrir mesa, anular,
  descuento, transferir, dividir mesa, dividir cuenta. Integrar a los roles.

### Facturación de la cuenta dividida (los 3 casos del owner)

Cada **liquidación** (§2.6) emite su propio `transaction` venta → su propio
comprobante, con su propio `customerId`. Una mesa puede generar **N facturas, una
por persona, cada una a su nombre/RUC**:

- **Partes iguales**: N liquidaciones de `total/N`; cada comensal que quiere
  factura informa su RUC/nombre → su comprobante por su parte. Quien no quiere
  factura → comprobante a consumidor final.
- **Por producto**: cada liquidación factura exactamente los ítems de esa persona
  (detalle real en la factura), a su nombre.
- **Por monto entregado**: la persona que se retira entregando 100.000 Gs recibe
  su factura por ese monto (a su nombre si lo pide); queda registrada como pago
  parcial de la mesa. El saldo se factura al cerrar, al/los que queden.

Como el módulo es **online-only** (§7), cada comprobante toma su número
**server-side atómico** (`UPDATE register SET registerInvoiceNumber = +1
RETURNING`) — secuencial, sin huecos, sin lease (el lease de `context/14 §9` es
solo para ventas simples offline). Respetar un punto de expedición por caja.
Edge: validar que la suma de partes = total de la mesa (con/ sin propina y cargo
de servicio) antes de cerrar; no permitir cerrar con saldo descubierto.

---

## 9. Decisiones

- **D1 — UI operativa → ✅ `app-next`** (no legacy/Alpine). El módulo operativo
  se construye en el nuevo POS React. **Depende de que `app-next` arranque**
  (`context/14`). Backend + schema + config (frontend) pueden avanzar antes.
- **D2 — Offline → ✅ ONLINE-ONLY.** El módulo de mesas NO funciona offline. El
  offline se reserva exclusivamente para ventas simples + creación de clientes.
  Elimina lease/reconciliación de mesas (ver §7).
- **D3 — Migración → ✅ corte limpio.** Se rehace el módulo completo, se elimina
  `type=11/12`, sin dual-run ni backfill (POS no está en producción real).
- **D4 — Estado limpieza → ✅ NO.** Al pagarse completa, la mesa vuelve directo a
  `free`. Sin estado intermedio de limpieza.
- **D5 — Propina/cargo de servicio → ✅ SÍ.** `tipAmount` por liquidación
  (`table_settlement`) + `serviceCharge` opcional a nivel sesión.

---

## 10. Fases

**Independientes de app-next (pueden arrancar ya):**
- **F0 — Schema + plumbing**: 6 tablas nuevas + `transaction.tableSessionId` +
  registro en `_getTableSchema()`. Migración. Corte limpio del `type=11/12`
  (eliminar `ncmSpaces` legacy + TableService/OrderService viejos).
- **F1 — Config**: `SectorService` + `TableService` CRUD + editor de salón/plano
  en frontend. Asignación de mozos.
- **Backend operativo**: `TableSessionService`, `ReservationService`,
  `SplitBillService`, endpoints `/v1/*` — se pueden tener listos y probados por
  API antes de que exista la UI operativa.

**Dependen de app-next (D1):**
- **F2 — Operación core**: plano operativo + abrir mesa (covers+mozo) + flujo de
  orden + estados + WS de status + acciones de mesa (unir, mover, **dividir mesa
  = mover ítems a mesa nueva**). **Online-only.**
- **F3 — Dividir cuenta + cobro por partes**: `SettlementService` (iguales / por
  producto / por monto entregado + pagos parciales) + propina/cargo de servicio +
  **factura por parte a nombre de cada persona** (numeración server-side atómica).
- **F4 — Reservas**: UI calendario/lista + sentar/no-show.
- **F5 — Pulido**: permisos por mozo, KDS por tiempos/firing, analítica
  (rotación, ventas por sector/mozo), propina (si D5).

---

## 11. Relación con otros docs
- `context/14-app-rewrite-analysis.md` — el `/app` se reescribe a Next; este
  módulo debe diseñarse backend-first para servir a ambos frontends, y su
  offline/numeración se apoya en el lease de §9 de ese doc.
- `context/04-modelo-de-dominio.md` — convenciones de schema (UUID, JSONB, tenant).
- Memoria `project_jerarquia_dominio` — company > outlet > depósito > caja; regla
  fiscal de punto de expedición.

---

## 12. Entregado 2026-08-23 — mozo, alias, mover/unir, exclusividad

Cierra cuatro pedidos del owner y parte de F1/F2/F5 de la §10.

| Pedido | Estado | Dónde |
|---|---|---|
| Selector de mozo al abrir mesa | Hecho | `open-space-dialog.tsx`, `edit-space-session-dialog.tsx` |
| Nombre libre de la mesa (alias de la SESIÓN) | Hecho | mig 163 `space_session.alias` |
| Mover / unir espacios desde el POS | Hecho | `SpaceSessionService::move()/merge()` |
| Mesas asignadas con exclusividad | Hecho | `SpaceOwnershipGuard` + `pos.space.override` |

**Lo que hubo que resolver antes de poder hacer el 4.º** — el realm `pos-app`
no tenía identidad de persona: el token es de la TABLET, `AUTHED_USER_ID` es
quien la pareó y `hasPermission()` resuelve el rol `device`, idéntico para
todos los que la usan. Mandar el `userId` en el body habría sido el botón
escondido que el owner pidió no hacer, con un `if` en el server.

Solución mínima que no toca el modelo de auth: `OperatorAssertion` — un token
HMAC que emite **solo** `/v1/unlock-pin.php` (que ya validaba el PIN
server-side) y viaja en `X-Operator-Token`, adjuntado por `posFetch`. No es una
sesión, no se revoca de a una y sin Bearer válido no vale nada; es una
afirmación acotada de "quien manda esto conocía el PIN de este contacto". La
sesión de operador de verdad sigue siendo `context/21-auth-rewrite.md`.
Detalle en `context/08-convenciones-criticas.md §56`.

**Decisiones de los casos borde de mover/unir** (razonadas en
`context/modules/12-espacios.md` regla 13):

- Pedidos ya en cocina: **ni se cancelan ni se re-emiten**. Cuelgan de la
  sesión, no del espacio; el nombre de la mesa sale de un JOIN vivo, así que
  alcanza con republicar las órdenes para que el KDS repinte.
- Pagos parciales: las filas del ledger **se mudan con su `transactionid`
  intacto**. Cada una es una venta real ya impresa — borrarlas falsea la caja,
  re-emitirlas duplica el documento fiscal.
- Unir sesiones con familias de cobro incompatibles (`items` vs
  `amount`/`share`): **se rechaza**. Produciría el estado que el módulo prohíbe
  por drift de stock (regla 2), y nadie lo pidió explícitamente.
- Numeración: ninguna de las dos operaciones emite documento, no consume
  correlativos.
- `mergedinto` (mig 163) distingue "se unió a otra mesa" de "se cerró vacía",
  que en BD eran el mismo estado.

**Fuera de la exclusividad a propósito**: `close()` y todo el camino de cobro.
Quien cobra es la caja, no el mozo; bloquearlos dejaría al cajero sin poder
cerrar la cuenta de una mesa ajena.

**Abierto (decisión de producto, no asumida)**: la exclusividad se activa sola
al asignar mozo, sin interruptor por comercio. Ver la pregunta en
`context/_feature-requests.md` § "Mesas asignadas a meseros".
