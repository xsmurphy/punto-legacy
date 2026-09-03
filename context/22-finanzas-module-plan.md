# 22 — Módulo de Finanzas (plan cerrado)

> Diseño para ejecución por sub-agente. Greenfield en `frontend/app/(panel)/finanzas/`
> + `api/lib/Finance/` + migración `72`. NO relitigar decisiones acá cerradas.

## 0. Filosofía — PYME sin conocimiento contable

El usuario es un emprendedor/pyme sin formación contable ni técnica. Por lo tanto:

- **NO partida doble.** Nada de débito/haber, asientos, libro mayor, balance de
  sumas y saldos. Modelo de **caja simple (single-entry)**: entradas y salidas.
- **Lenguaje llano** (es-PY): "Cuentas", "Entró / Salió", "Categorías",
  "Cheques", "Conciliar con el banco". Nunca jerga contable.
- **Configuración mínima**: al entrar por primera vez, se auto-crean cuentas y
  categorías por defecto. El usuario puede usar el módulo sin configurar nada.
- **Auto-poblado**: los movimientos se generan solos desde ventas, compras,
  gastos y pagos que YA se registran en el POS/panel. La carga manual es la
  excepción (ajustes, movimientos que no pasan por el sistema).
- **Números exactos** (roadmap §501): todo saldo debe cuadrar. Idempotencia
  estricta para no duplicar movimientos derivados.

## 1. Los 5 conceptos que ve el usuario

1. **Cuentas** — dónde está la plata: Caja (efectivo), Cuenta bancaria,
   Billetera (Tigo Money/Billetera Personal). Cada una muestra su **saldo**.
2. **Movimientos** — cada entrada y salida (con categoría, cuenta, fecha, medio).
   Auto-generados desde ventas/compras/gastos + carga manual.
3. **Categorías** — el "plan de cuentas" simplificado: árbol de 1 nivel de
   **Ingresos** y **Egresos** (Ventas, Otros ingresos / Proveedores, Sueldos,
   Alquiler, Servicios, Impuestos, Otros). Editables; las default no se borran.
4. **Cheques** — emitidos y recibidos, con estado (pendiente → cobrado/depositado
   / rechazado) y fecha de cobro. Al cobrarse generan un movimiento.
5. **Conciliación bancaria** — comparar los movimientos del sistema de una cuenta
   contra el extracto del banco, tildar los que coinciden, ver la diferencia.

## 2. Modelo de datos — migración `72_finance.sql`

Todo `companyId`-scoped (multi-tenant, §08). PKs uuid `gen_random_uuid()`.
Montos `NUMERIC(14,2)` (el display respeta `bootstrap.decimal`; Gs = sin
decimales en UI). Columnas físicas **en minúscula** (evita el bug tipo `drawerId`,
ver [[project_autoload_services_lowercase]] / mig 71). `data jsonb` para extras.
`created_at timestamptz default now()`. Índices por `companyid`.

### `fin_account` — cuentas
`accountid, companyid, name, type ('cash'|'bank'|'wallet'), openingbalance NUMERIC(14,2) default 0, currentbalance NUMERIC(14,2) default 0, bankname, accountnumber, outletid (nullable = global), issystem bool default false, status smallint default 1, created_at, data`
- `currentbalance` = cache; se actualiza transaccionalmente en cada insert/void de movimiento. Recomputable (openingbalance + Σ movimientos activos).

### `fin_category` — categorías (plan de cuentas simple)
`categoryid, companyid, name, kind ('income'|'expense'), parentid (nullable, 1 nivel), sortorder int default 0, issystem bool default false, status smallint default 1, created_at, data`

### `fin_movement` — el ledger (entradas/salidas)
`movementid, companyid, accountid, categoryid (nullable), kind ('income'|'expense'), amount NUMERIC(14,2) (SIEMPRE positivo; el signo lo da kind), date timestamptz, description, paymentmethod, source ('manual'|'sale'|'purchase'|'expense'|'credit_payment'|'check'|'transfer'|'opening'), sourceid uuid (nullable), transfergroupid uuid (nullable), checkid uuid (nullable), reconciliationid uuid (nullable), reconciled bool default false, reconciled_at, userid, outletid, created_at, data`
- **Idempotencia**: `UNIQUE (companyid, source, sourceid) WHERE sourceid IS NOT NULL` — un mismo sale/purchase/expense/cheque genera 1 solo movimiento.
- Transferencia entre cuentas = 2 movimientos (1 expense origen + 1 income destino) con el mismo `transfergroupid`.

### `fin_check` — cheques
`checkid, companyid, direction ('issued'|'received'), accountid (nullable = cuenta bancaria propia si emitido), bankname, checknumber, amount NUMERIC(14,2), issuedate, duedate, contactid (nullable), partyname (texto libre si no hay contacto), categoryid (nullable), status ('pending'|'deposited'|'cleared'|'bounced'|'cancelled'), cleareddate, description, created_at, data`
- Transición a `cleared`/`deposited` → inserta `fin_movement` (egreso si issued, ingreso si received) con `source='check', sourceid=checkid` (idempotente). Volver a `pending`/`bounced` → borra/anula ese movimiento.

### `fin_reconciliation` — sesiones de conciliación
`reconciliationid, companyid, accountid, statementdate, statementbalance NUMERIC(14,2), status ('open'|'closed'), closed_at, userid, created_at, data`
- Al conciliar: se tildan movimientos (`reconciled=true, reconciliationid=...`).
- Diferencia mostrada = `statementbalance − (saldo conciliado del sistema)`. Cerrar solo cuando diferencia = 0 (con opción "ajuste" que crea un movimiento de ajuste categorizado).

## 3. Config mínima: método de pago → cuenta

Única config real. Un mapa `methodId → accountId` (guardado en
`company.config.settingObj.finAccountMap` JSONB, MERGE no-destructivo como el
resto de settingObj — ojo [[project_autoload_services_lowercase]] y el bug de
`readSettingObj` ya arreglado). `methodId` es el `taxonomyId` real del método
de pago (tabla `taxonomy`, `taxonomyType='paymentMethod'`, scoped por
`companyId`) — los métodos son los que el tenant creó en su propio CRUD de
medios de pago, no una lista fija de keys. Editable en Ajustes del módulo. Con
esto cada venta/pago cae sola en la cuenta correcta sin que el usuario haga
nada.

**Regla de negocio (cerrada por el owner, actualizada 2026-07-02):**
- **"Efectivo" es una cuenta del sistema, SIEMPRE presente** (`type='cash'`,
  `issystem=true`, no se puede borrar; se auto-crea en el seed). El método de
  pago del tenant cuyo `taxonomyName` sea "Efectivo" (case-insensitive) SIEMPRE
  mapea a esa cuenta — fijo, no editable, no se persiste en `finAccountMap`.
- **Los demás métodos** (los que el tenant haya creado: tarjeta de débito,
  transferencia, billetera, cheque, etc.) tienen un **selector de banco/cuenta**:
  el usuario elige a qué cuenta bancaria van sus operaciones. Default de un
  método no-cash sin banco asignado: cae en Efectivo hasta que el usuario le
  asigne un banco (nunca se pierde el movimiento).
- **El usuario puede crear todas las cuentas bancarias que quiera** (`type='bank'`),
  y asignar cada método a la que corresponda. La UI de Ajustes muestra la lista de
  métodos de pago reales del tenant con un dropdown "¿A qué cuenta va?" por
  método (el que resuelva a "Efectivo" queda fijo, los demás elegibles entre las
  cuentas bancarias creadas).

## 4. Integración automática (movimientos derivados)

`FinanceLedger::record($companyId, [...])` — helper idempotente (respeta el
UNIQUE). Se invoca **best-effort, después del commit** de cada operación (nunca
dentro de su TX; si falla, loguea y no rompe la venta):
- **Venta contado (type 0)** → income, categoría "Ventas", cuenta = finAccountMap[método].
- **Pago de crédito (type 5)** → income "Ventas".
- **Compra (type 1)** → expense "Proveedores", cuenta = finAccountMap[método].
- **Gasto (`expenses`)** → expense, categoría mapeada desde `expensesnameid`.
- **Anulación** (status→6 / void) → borra/anula el movimiento derivado (mismo sourceid).

**Backfill histórico**: script `api/database/seeds/finance_backfill.php` idempotente
que recorre transaction(0,1,3,5)+expenses del tenant y crea los movimientos
faltantes. Se corre una vez por tenant (o desde un botón "Importar histórico" en
la UI del módulo).

> FASE: la integración auto + backfill es **Fase 3**. Fases 1-2 funcionan con
> carga manual + cheques + conciliación; no bloquean.

## 5. Backend (`api/lib/Finance/`, namespace `Punto\Api\Finance`)

Services (patrón: constructor con companyId explícito, `ncmExecute/ncmInsert/
ncmUpdate`, nunca globals; autoloader `lib/Finance/` matchea el namespace):
- `AccountService` — CRUD cuentas + recompute de saldo + seed defaults.
- `CategoryService` — CRUD categorías + seed defaults (issystem no borrable).
- `MovementService` — CRUD movimientos manuales, transferencias, listado con
  filtros (cuenta, categoría, rango fecha, kind, texto), actualiza currentbalance en TX.
- `CheckService` — CRUD cheques + transición de estado (genera/borra movimiento).
- `ReconciliationService` — abrir/cerrar sesión, tildar/destildar movimientos, diferencia.
- `FinanceLedger` — helper de movimientos derivados (Fase 3).

Endpoints REST bajo `api/v1/finance/` (realm `panel`, `hasPermission` nuevo
`finance.manage`; agregar al PermissionCatalog):
- `GET/POST/PUT/DELETE /v1/finance/accounts`
- `GET/POST/PUT/DELETE /v1/finance/categories`
- `GET/POST/PUT/DELETE /v1/finance/movements` (+ `?resource=transfer`)
- `GET/POST/PUT /v1/finance/checks`
- `GET/POST /v1/finance/reconciliations` (+ tildar movimientos)
- `GET/POST /v1/finance/config` (finAccountMap)
- `GET /v1/finance/summary` (dashboard: saldos por cuenta, ingresos/egresos del período, flujo)
- `GET /v1/finance/reports?by=category|account&from=&to=` (montos agregados —
  2026-07-02)

## 6. Frontend (`frontend/app/(panel)/finanzas/`)

Respetar **§14 UI conventions** y [[feedback_shadcn_mandatory]] / [[feedback_data_tables_convention]]
/ [[feedback_money_inputs_convention]]: shadcn primitives, `<DataTable>` para
listados, `<MoneyInput>` para montos, `formatMoney` para mostrar, sin hex, sin
emojis, tipografía canónica, sin `<table>`/`<input>` nativos.

Layout: página raíz `/finanzas` = **Dashboard** (tarjetas de saldo por cuenta +
ingresos/egresos del período + mini flujo). **IA de tabs (rediseñada 2026-07-02,
decisión cerrada del owner)**: 3 grupos por naturaleza (Operación / Reportes /
Configuración), separador vertical sutil entre grupos en el mismo `TabsList`
(`frontend/app/(panel)/finanzas/layout.tsx`):

**Operación** (día a día):
- **Resumen** (`/finanzas`) — saldos + KPIs + últimos movimientos.
- **Movimientos** (`/finanzas/movimientos`) — DataTable con filtros; botón
  "Registrar entrada/salida" y "Transferencia entre cuentas" (MoneyInput, cuenta,
  categoría, fecha, nota). Fila → detalle/editar.
- **Cuentas** (`/finanzas/cuentas`) — lista de cuentas con saldo; alta/edición
  (nombre, tipo, saldo inicial, banco). Click → movimientos filtrados por cuenta.
- **Cheques** (`/finanzas/cheques`) — DataTable (dirección, banco, nº, monto,
  vencimiento, estado con chip). Acciones: marcar cobrado/depositado/rechazado.
  Vista "próximos a vencer".
- **Conciliación** (`/finanzas/conciliacion`) — elegir cuenta + fecha extracto +
  saldo extracto → lista de movimientos con checkbox "coincide"; footer con
  saldo sistema / saldo extracto / **diferencia** (verde si 0). Cerrar sesión.

**Reportes** (`/finanzas/reportes`, nuevo): DateRangePicker + sub-tabs
"Por categoría" (default) / "Por cuenta", cada uno un `<DataTable>` con
nombre + ingresos + egresos + neto. Consume
`GET /v1/finance/reports?by=category|account&from=&to=` →
`MovementService::totalsByCategory()/totalsByAccount()` (agregación SQL,
fila "Sin categoría"/"Sin cuenta" para `categoryid`/`accountid` NULL).

**Configuración** (`/finanzas/configuracion`, ex-"Ajustes"): sub-tabs
"Categorías" (CRUD completo, movido tal cual desde `/finanzas/categorias` —
antes vivía suelto al tope y se confundía con un reporte) y "Medios de pago"
(mapa método de pago→cuenta + botón "Importar histórico"/backfill, movido
desde `/finanzas/ajustes`). `/finanzas/categorias` y `/finanzas/ajustes`
quedan como redirect a `/finanzas/configuracion` (no rompen bookmarks).

Hooks react-query en `frontend/hooks/use-finance-*.ts` (o `use-finance.ts`),
api-client (`api.get/post/put/del`), invalidación al mutar. Agregar el ítem
**"Finanzas"** al nav del panel (sidebar) con un icono lucide (`Landmark` o `Wallet`).

## 7. UX PYME — detalles que bajan la fricción

- Onboarding cero: al abrir Finanzas la primera vez, auto-seed de la cuenta
  **"Efectivo"** (`type='cash'`, `issystem=true`, no borrable) + categorías
  default. NO se crea banco placeholder: el usuario crea sus bancos. Banner
  suave "Creá tus cuentas bancarias y asigná tus medios de pago" opcional, no
  bloqueante.
- Saldos siempre visibles arriba. Colores: entra = `var(--chart-1)` (verde),
  sale = `var(--destructive)`. Nunca rojo agresivo para saldos normales.
- Empty states con `<EmptyState>` explicando qué es cada cosa en 1 frase.
- Fechas y montos con los helpers del tenant. Cheques: chip de estado
  (pendiente=ámbar, cobrado=verde, rechazado=rojo, anulado=gris) — mismo patrón
  que el chip de crédito del POS.

## 8. Fases de ejecución

1. **Fase 1 — Fundación**: migración 72 + AccountService/CategoryService/
   MovementService + endpoints accounts/categories/movements/config + seed
   defaults + UI (Resumen, Cuentas, Categorías, Movimientos, Ajustes) + nav +
   permiso `finance.manage`. Todo con carga manual funcionando. `npm run build` + `php -l`.
2. **Fase 2 — Cheques + Conciliación** (implementada 2026-07-02):
   CheckService/ReconciliationService + endpoints `checks.php`/
   `reconciliations.php` + UI (tabs Cheques, Conciliación). Cheque→movimiento
   en `cleared` (idempotente vía UNIQUE source+sourceid); revertir estado
   anula el movimiento. Conciliación: toggle de `reconciled` no toca
   `currentbalance`; cierre con diff=0 o ajuste automático (source=
   'adjustment'). Selector de contacto en el form de cheques quedó fuera de
   alcance (no hay combobox de contactos reusable todavía) — `partyName`
   texto libre cubre el caso de uso.
3. **Fase 3 — Auto-integración** (implementada 2026-07-02): `FinanceLedger`
   (`api/lib/Finance/FinanceLedger.php`) re-lee la fila de origen por id — el
   mismo código sirve para el hook en vivo y para el backfill. Hooks
   post-commit best-effort (try/catch que solo logea) en `sales.php`,
   `credit-payments.php`, `purchases.php` (create + void) y `transactions.php`
   (`voidTransaction` → `voidBySource`). `DrawerService::addExpense/addIncome`
   migrados a `ncmInsert` para recuperar el id y engancharlo al ledger.
   - **Pago dividido**: mig 73 cambia el UNIQUE a
     `(companyid, source, sourceid, accountid)`. Una venta con varios métodos
     que caen en cuentas distintas genera N movimientos (1 por cuenta,
     sumando líneas); re-correr el backfill es idempotente.
   - **Idempotencia de saldo ATÓMICA**: `MovementService::recordDerivedMovement()`
     usa `INSERT ... ON CONFLICT DO NOTHING RETURNING` — el delta de
     `currentbalance` se aplica una única vez, solo cuando el RETURNING trae
     fila (sin ventana TOCTOU).
   - **Backfill**: `api/database/seeds/finance_backfill.php` (CLI) +
     `POST /v1/finance/backfill` (permiso `finance.manage`, advisory lock por
     tenant) + botón "Importar histórico" en Finanzas → Ajustes.
   - **Devoluciones** (implementado 2026-07-30): `FinanceLedger::recordReturn`
     (`transactionType=6`, `source='return'`, `kind='expense'`, categoría
     "Devoluciones" creada on-demand por `CategoryService::ensureReturnsCategoryId`).
     Hook post-commit best-effort en `ReturnService::create`; `voidTransaction`
     agrega `'return'` a los sources que revierte; el backfill incluye type=6.
     `refundMode='credit'` NO toca caja — sube `contactStoreCredit`, que es un
     pasivo con el cliente. El filtro es del wrapper: `recordPaymentLines`
     descarta los medios que no mueven plata (`storeCredit`/`inCredit`/
     `points`/`giftcard`, por slug o por `systemKey` del método), así que
     TAMBIÉN deja de imputar a Efectivo las VENTAS pagadas con crédito interno,
     puntos o gift card (inflaban el saldo con plata que nunca entró).
     Los movimientos históricos de esas ventas siguen en la BD: limpiar con
     `finance_revert_derived.php` + rehacer backfill si el saldo importa.
     `ReturnService` pasó a persistir el monto del pago en `total` (antes
     `amount`, clave que ningún lector del sistema entiende → la línea se leía
     como 0; también rompía los medios de pago de la nota de crédito).
   - **TODO Fase 4**: backfill sin cap de tiempo (chunk/queue si un tenant crece mucho).
     Dashboard de flujo de caja (cashflow) queda pendiente.
4. **CRUD de medios de pago** (implementado 2026-07-02, branch `pay-methods-crud`):
   `PaymentMethodService` (`api/lib/PaymentMethods/`) + endpoint
   `/v1/payment-methods` (GET/POST/PUT/DELETE), CRUD completo sobre
   `taxonomy` (`taxonomyType='paymentMethod'`). Flags de comportamiento
   (code, hasChange, requiresIdentifier, identifierLabel/Placeholder,
   systemKey) en `taxonomyExtra` JSONB. `accountId` se persiste
   EXCLUSIVAMENTE vía `ConfigService::update` (nunca escrito directo).
   Auto-seed idempotente (Efectivo/T.Crédito/T.Débito) si el tenant no tiene
   ninguno. "Efectivo" no se puede borrar y su `accountId` se ignora siempre
   (cae fijo en la cuenta Efectivo del sistema).
   - Tab "Medios de pago" en Settings → Catálogo (`CatalogManager` genérico
     ahora soporta `type: "switch"|"select"` + `transformPayload`).
   - `ConfigService::resolveAccountId` es dual-path: UUID (taxonomyId, ventas
     nuevas) vs slug legacy (backfill histórico, intacto).
   - POS bootstrap (`/api/pos/bootstrap`) trae medios de pago reales de
     `/v1/payment-methods` en vez del hardcode; degrada a
     `FALLBACK_PAYMENT_METHODS` si el fetch falla o el tenant no tiene
     ninguno — nunca bloquea ni tira 500.
   - `pay-dialog.tsx` re-keyeado: `systemKey` (cash/giftcard/internal) en vez
     de comparar contra el `id` (taxonomyId, ahora varía por tenant) para el
     flujo de giftcard y agrupación de métodos secundarios.

5. **Reagrupación de IA + Reportes** (implementado 2026-07-02): tabs de
   `/finanzas` reorganizados en 3 grupos por naturaleza (ver §6). Categorías
   dejó de estar al tope (se confundía con un reporte) y se movió a
   Configuración junto con Medios de pago (ex-Ajustes). Nueva página Reportes
   con sub-reportes "Por categoría"/"Por cuenta" (`totalsByCategory()`/
   `totalsByAccount()` en `MovementService`, endpoint `reports.php`).

## 9. Reglas del proyecto (obligatorias)

- Multi-tenant: TODA query filtra por `companyid`.
- Columnas físicas lowercase (bug case-sensitivity mig 71).
- shadcn obligatorio, `<DataTable>`, `<MoneyInput>`, `formatMoney`, sin hex, sin
  emojis, §14 tipografía. Leer §14 antes de tocar JSX.
- Commits por slice, `npm run build` (enforça TS) + `php -l` antes de cada commit.
  Branch `frontend/finanzas` o directo en main si toca api+frontend acoplado.
- code-reviewer en el commit de la migración/schema (alto riesgo).

---

# 10. Gasto devengado en el reporte por categoría

> **Estado:** plan abierto 2026-09-03. El owner cerró D1 (el gasto se reconoce
> al COMPRAR, no al pagar). D2-D5 son propuestas sin su OK.

## 10.1 El pedido, y la tensión con la §0

Pedido del owner: "cada compra debe registrar un movimiento en categorías si
está seleccionada una categoría", y una compra a crédito **todavía impaga** ya
tiene que verse como gasto en el reporte por categoría.

Eso es criterio de DEVENGADO, y la §0 de este doc define el módulo como caja
simple: `fin_movement` son "entradas y salidas" de plata. La tensión es real y
no se resuelve ignorando ninguna de las dos: **`fin_movement` sigue siendo
caja; lo que cambia es de dónde lee el REPORTE de gastos por categoría.**

Por qué no se resuelve insertando un `fin_movement` al comprar a crédito: ese
movimiento debitaría una cuenta que todavía no pagó nada. `fin_movement`
alimenta los saldos por cuenta, el flujo de efectivo (`context/60` D2) y la
conciliación bancaria. Un asiento por plata que no se movió rompe las tres, y
rompe además el invariante de cuadre que `context/60` declara obligatorio.

## 10.2 Estado verificado hoy (2026-09-03)

| Caso | ¿Movimiento? | ¿Con la categoría elegida? |
|---|---|---|
| Compra contado | Sí | **Sí** — hasta dividida por línea |
| Compra crédito, al crear | **No** | — |
| Compra crédito, al pagarse | Sí | **No** — cae en "Proveedores" |

- El split por categoría del contado está BIEN hecho:
  `PurchasesService::create()` resuelve la precedencia línea > cabecera > ítem
  y la persiste en `meta.details`; `FinanceLedger::resolveCategorySplit()`
  agrupa y prorratea el descuento de cabecera en porciones exactas.
- Una compra a crédito NO genera línea de pago a propósito
  (`PurchasesService::create()`: `$isCredit` ⇒ `$payMethod = ''` ⇒
  `$paymentTypeJson = null`), y `recordPurchase()` corta temprano con el guard
  `empty($lines)`. Sin línea de pago no hay movimiento — correcto bajo caja.
- `FinanceLedger::recordCreditPayment()` (transactionType 5) crea el
  movimiento con `ensurePurchasesCategoryId()` —la categoría genérica— y sin
  centro de costo. **Hoy la categoría elegida en una compra a crédito se pierde
  entera.**

## 10.3 Decisiones

### D1 — El gasto se reconoce al COMPRAR (cerrada por el owner 2026-09-03)

El reporte de gastos por categoría muestra la compra en su fecha de compra,
esté pagada o no. Lo que se paga después es la cancelación de una deuda, no un
gasto nuevo.

### D2 [?] — El pago a proveedor NO se categoriza como gasto

Corolario directo del D1, y es lo que hace que el "fix obvio" sea el
equivocado: hacer que `recordCreditPayment()` herede la categoría de la compra
—que era el arreglo natural bajo caja— bajo devengado CONTARÍA EL GASTO DOS
VECES, una al comprar y otra al pagar.

El movimiento del pago se queda como está para la caja (sale plata de una
cuenta real, el flujo de efectivo y la conciliación lo necesitan), pero el
reporte de gastos por categoría lo EXCLUYE por `source = 'purchase_payment'`.

### D3 [?] — El reporte lee dos fuentes, el ledger no cambia

`MovementService::totalsByCategory()` pasa a unir:

1. `fin_movement` de gastos que NO vienen de una compra a crédito (compras al
   contado, gastos manuales, movimientos del cajón), excluyendo
   `source = 'purchase_payment'` por el D2.
2. Las compras a CRÉDITO por su fecha de compra, con el mismo
   `resolveCategorySplit()` que ya usa el contado — no una segunda
   interpretación de la precedencia de categorías.

El punto de la reutilización no es ahorrar código: si el devengado resolviera
la categoría por su cuenta, la MISMA compra podría caer en una categoría bajo
caja y en otra bajo devengado, y el reporte dejaría de ser explicable.

### D4 [?] — Solo cambia el reporte por categoría y por centro de costo

El corte por CUENTA sigue siendo puramente de caja: una cuenta es plata real y
una compra impaga no tocó ninguna. Mezclar devengado ahí produciría un total
por cuenta que no cuadra con su saldo.

Mismo criterio para el flujo de efectivo (`context/60`) y la conciliación: no
se tocan.

### D5 [?] — La pantalla dice cuál criterio está mirando

Un total que incluye compras impagas NO coincide con la plata que salió, y esa
diferencia va a leerse como un error del sistema si no está dicha. El reporte
declara el criterio y ofrece ver el neto de caja al lado.

## 10.4 Fases

| Fase | Qué entrega | Depende de |
|------|-------------|-----------|
| **G0** | **Medir**: cuántas compras a crédito impagas hay y cuántas traen categoría. Si son marginales, el orden de G1/G2 se invierte. | — |
| **G1** | Excluir `source = 'purchase_payment'` del corte por categoría/centro de costo (D2). Solo, deja de contar la compra a crédito en "Proveedores" al pagarse. | — |
| **G2** | Sumar las compras a crédito por fecha de compra, reusando `resolveCategorySplit()` (D3). Es el corazón del devengado. | G1 |
| **G3** | Cartel de criterio + neto de caja al lado (D5). | G2 |
| **G4** | Mismo tratamiento para el centro de costo, que hoy también se pierde en el pago. | G2 |

## 10.5 El arnés

G2 no se mergea sin test contra Postgres real:

- Una compra a crédito con categoría aparece en el reporte por su FECHA DE
  COMPRA, antes de cualquier pago.
- Pagarla NO cambia el total del reporte por categoría (no se duplica).
- Pagarla SÍ mueve el saldo de la cuenta y el flujo de efectivo.
- Una compra al contado da EXACTAMENTE el mismo total por categoría que la
  misma compra a crédito ya pagada.
- El corte por cuenta no cambia con este trabajo.

## 10.6 Arquitecturas rechazadas

- **Insertar un `fin_movement` al comprar a crédito** — ver §10.1: debita una
  cuenta que no pagó y rompe saldos, flujo de efectivo y conciliación.
- **Que `recordCreditPayment()` herede la categoría de la compra** — era el
  arreglo correcto bajo caja y es un DOBLE CONTEO bajo devengado (D2). Se deja
  escrito porque es la propuesta que sale sola al mirar el bug sin el modelo.
- **Una columna "devengado" en `fin_movement`** — convierte el ledger de caja
  en dos ledgers conviviendo en una tabla, y cada consumidor
  (saldos/flujo/conciliación/reportes) tendría que acordarse de filtrarla. El
  que se olvide, rompe. La §0 de este doc existe para evitar exactamente eso.
- **Partida doble** — sigue rechazada (§0). El devengado del reporte de gastos
  no la necesita ni la introduce.
