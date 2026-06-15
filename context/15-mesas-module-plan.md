# 15 — Plan: módulo de gestión de mesas (restaurante)

> **Creado:** 2026-06-15. **Estado:** plan de diseño, no iniciado.
> Reemplaza el módulo de "espacios" actual (`ncmSpaces`), que es un grid
> numérico fijo sin entidad de mesa real. Objetivo: gestión de mesas de nivel
> restaurante (sectores, mozos, reservas, comensales, split de cuentas, estados).

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
2. **Config (definir sectores/mesas/layout) en panel-next** — ya es React/shadcn.
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

### 5.1 Config (en panel-next — React/shadcn)
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
  (`context/14`). Backend + schema + config (panel-next) pueden avanzar antes.
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
  en panel-next. Asignación de mozos.
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
