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

Única config real. Un mapa `paymentMethodKey → accountId` (guardado en
`company.config.settingObj.finAccountMap` JSONB, MERGE no-destructivo como el
resto de settingObj — ojo [[project_autoload_services_lowercase]] y el bug de
`readSettingObj` ya arreglado). Editable en Ajustes del módulo. Con esto cada
venta/pago cae sola en la cuenta correcta sin que el usuario haga nada.

**Regla de negocio (cerrada por el owner):**
- **"Efectivo" es una cuenta del sistema, SIEMPRE presente** (`type='cash'`,
  `issystem=true`, no se puede borrar; se auto-crea en el seed). **Todo pago
  recibido en efectivo va SIEMPRE a "Efectivo"** — el mapeo del método `efectivo`
  a la cuenta Efectivo es fijo (no editable).
- **Los demás métodos** (tarjeta de débito, tarjeta de crédito, transferencia,
  billetera, cheque, etc.) tienen un **selector de banco/cuenta**: el usuario
  elige a qué cuenta bancaria van sus operaciones. Ej.: tarjeta de débito → Banco
  X → todas las ventas con tarjeta de débito entran en Banco X. Default de un
  método no-efectivo sin banco asignado: cae en Efectivo hasta que el usuario le
  asigne un banco (nunca se pierde el movimiento).
- **El usuario puede crear todas las cuentas bancarias que quiera** (`type='bank'`),
  y asignar cada método a la que corresponda. La UI de Ajustes muestra la lista de
  métodos de pago con un dropdown "¿A qué cuenta va?" por método (efectivo fijo en
  Efectivo, los demás elegibles entre las cuentas bancarias creadas).

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

## 6. Frontend (`frontend/app/(panel)/finanzas/`)

Respetar **§14 UI conventions** y [[feedback_shadcn_mandatory]] / [[feedback_data_tables_convention]]
/ [[feedback_money_inputs_convention]]: shadcn primitives, `<DataTable>` para
listados, `<MoneyInput>` para montos, `formatMoney` para mostrar, sin hex, sin
emojis, tipografía canónica, sin `<table>`/`<input>` nativos.

Layout: página raíz `/finanzas` = **Dashboard** (tarjetas de saldo por cuenta +
ingresos/egresos del período + mini flujo). Tabs/sub-rutas (patrón de unificación
ya acordado):
- **Resumen** (`/finanzas`) — saldos + KPIs + últimos movimientos.
- **Movimientos** (`/finanzas/movimientos`) — DataTable con filtros; botón
  "Registrar entrada/salida" y "Transferencia entre cuentas" (MoneyInput, cuenta,
  categoría, fecha, nota). Fila → detalle/editar.
- **Cuentas** (`/finanzas/cuentas`) — lista de cuentas con saldo; alta/edición
  (nombre, tipo, saldo inicial, banco). Click → movimientos filtrados por cuenta.
- **Categorías** (`/finanzas/categorias`) — árbol simple Ingresos/Egresos, editable.
- **Cheques** (`/finanzas/cheques`) — DataTable (dirección, banco, nº, monto,
  vencimiento, estado con chip). Acciones: marcar cobrado/depositado/rechazado.
  Vista "próximos a vencer".
- **Conciliación** (`/finanzas/conciliacion`) — elegir cuenta + fecha extracto +
  saldo extracto → lista de movimientos con checkbox "coincide"; footer con
  saldo sistema / saldo extracto / **diferencia** (verde si 0). Cerrar sesión.
- **Ajustes** (`/finanzas/ajustes`) — mapa método de pago→cuenta + botón
  "Importar histórico" (backfill).

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
2. **Fase 2 — Cheques + Conciliación**: CheckService/ReconciliationService +
   endpoints + UI (Cheques, Conciliación).
3. **Fase 3 — Auto-integración**: FinanceLedger + hooks post-commit en
   Sale/Purchase/Expense/CreditPayment + backfill histórico + dashboard de flujo.

## 9. Reglas del proyecto (obligatorias)

- Multi-tenant: TODA query filtra por `companyid`.
- Columnas físicas lowercase (bug case-sensitivity mig 71).
- shadcn obligatorio, `<DataTable>`, `<MoneyInput>`, `formatMoney`, sin hex, sin
  emojis, §14 tipografía. Leer §14 antes de tocar JSX.
- Commits por slice, `npm run build` (enforça TS) + `php -l` antes de cada commit.
  Branch `frontend/finanzas` o directo en main si toca api+frontend acoplado.
- code-reviewer en el commit de la migración/schema (alto riesgo).
