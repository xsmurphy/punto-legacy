<!-- REGLA GLOBAL de estructura de pantallas del panel. Lectura OBLIGATORIA
     antes de crear o reestructurar una pantalla de `frontend/app/(panel)`.
     Este doc NO reemplaza a `context/14` ni a `context/20`: los ORGANIZA por
     arquetipo y los referencia por sección y fecha. Si algo acá contradice a
     14/20, se señala y se lleva al owner (ver §11, resueltas 2026-09-18). -->

# 84 — Arquetipos de pantalla

> Pedido del owner (2026-09-18): una regla global sobre cómo se estructuran
> las entidades y los reportes, para después hacer una pasada y ver si todos
> los sectores la siguen. El síntoma: la misma función tiene distinto nombre y
> lugar según la sección (artículos edita en "Perfil", primera pestaña;
> clientes en "Datos", última), cada sección arma sus tarjetas crudas a mano,
> hay títulos en MAYÚSCULAS escritos a mano, bloques enteros para mostrar un
> solo atributo y números repetidos. **Objetivo: que el usuario, al entrar a
> cualquier sector, ya sepa dónde está cada cosa.**

## Estado de las reglas de este doc

Cada regla lleva una marca:

- **[OWNER]** — decidida por el owner (2026-09-18 o antes, con fecha). No se
  relitiga.
- **[VIGENTE]** — ya existe en `context/14`/`context/20` o en memoria; acá solo
  se ubica en su arquetipo, con la referencia.
- **[PROPUESTA]** — derivada del relevamiento del código, SIN OK del owner.
  Se aplica en la pasada solo después de su OK.

## Índice

- [0. Cómo se usa](#0-cómo-se-usa)
- [1. Los arquetipos](#1-los-arquetipos)
- [2. Reglas transversales](#2-reglas-transversales)
- [3. Ficha de entidad](#3-ficha-de-entidad)
- [4. Reporte](#4-reporte)
- [5. Listado](#5-listado)
- [6. Documento](#6-documento)
- [7. Ajustes](#7-ajustes)
- [8. Tablero](#8-tablero)
- [9. Herramienta](#9-herramienta)
- [10. Enforcement: componentes y guards](#10-enforcement-componentes-y-guards)
- [11. Contradicciones resueltas (owner 2026-09-18)](#11-contradicciones-resueltas-owner-2026-09-18)
- [12. Checklist de auditoría](#12-checklist-de-auditoría)
- [13. Inventario de pantallas (2026-09-18)](#13-inventario-de-pantallas-2026-09-18)

---

## 0. Cómo se usa

1. Antes de crear una pantalla, decidí **de qué arquetipo es** (§1). Toda
   pantalla del panel es de UNO solo. Si parece de dos, casi siempre es una
   ficha o un reporte con pestañas, y la otra mitad es una pestaña.
2. Construila con el **componente canónico** del arquetipo (§10). La
   estructura la impone el componente, no la memoria de quien escribe.
3. Aplicá las **reglas transversales** (§2), que valen para todos.
4. Revisá la **checklist** del arquetipo (§12) antes de cerrar.
5. Registrá la ruta en el registro de arquetipos (§10.3). El guard falla si
   una `page.tsx` nueva no está registrada.

Lo que NO es este doc: no define tokens, colores, tamaños de modal ni formatos.
Eso es `context/14` (reglas operativas) y `context/20` (design system). Acá se
define **qué zonas tiene cada tipo de pantalla, en qué orden y con qué
nombre**.

---

## 1. Los arquetipos

| Arquetipo | Qué es | Ejemplos |
|---|---|---|
| **Ficha de entidad** | Una cosa con identidad propia que se consulta y se edita: tiene resumen, datos y colecciones hijas | artículo, cliente/proveedor, persona del equipo, sucursal, lista de precios |
| **Reporte** | Lectura de un período (o foto a hoy) con números, gráfico y detalle exportable | `/reports/*`, previsión de finanzas |
| **Listado** | La colección de una entidad o documento, para buscar, filtrar y entrar | `/contacts`, `/items`, `/remisiones`, `/settings/roles` |
| **Documento** | Un comprobante u operación con número, estado, líneas y totales — se ve (vista) o se arma (editor) | transacción, orden, compra, borrador de compra, orden de pago, remisión, transferencia, ajuste y conteo de stock, lote de producción |
| **Ajustes** | Configuración del comercio y sus catálogos | `/settings?section=…`, `/settings/catalog` |
| **Tablero** *(sumado en el relevamiento)* | Portada de un área: números del momento + accesos, sin detalle propio | `/` (dashboard), `/finanzas` (resumen), `/reports` (índice) |
| **Herramienta** *(sumado en el relevamiento)* | Superficie de trabajo con interacción propia que no encaja en las anteriores | `/chat`, `/items/barcodes`, editor de plantillas, editor de espacios, `/finanzas/conciliacion` |

Tablero y Herramienta se suman porque existen en el código y forzarlos a otro
arquetipo los rompe. **Herramienta es la excepción, no la salida fácil:** una
pantalla nueva entra ahí solo si tiene una interacción que ningún otro
arquetipo cubre (canvas, chat, conciliación lado a lado). Una pantalla de
datos con formulario NO es herramienta.

**Redirects** (`/settings/team`, `/finanzas/ajustes`, `/finanzas/categorias`,
`/finanzas/reportes`) no tienen arquetipo: se registran como `redirect`.

---

## 2. Reglas transversales

Valen para TODO arquetipo.

| # | Regla | Estado / fuente |
|---|---|---|
| T1 | Título de página = `<h1 className="text-2xl font-semibold">`, uno solo por pantalla. Lo pinta el armazón del arquetipo, no el call-site | [VIGENTE] `context/14` Regla #1; `context/20` §6 "Header de página" |
| T2 | **BackLink canónico** — un solo componente, ghost con hover de fondo (`Button variant="ghost" size="sm"` + `ArrowLeft size-3.5`, texto `text-xs text-muted-foreground`). La forma que ya tiene `BackButton` en `transactions/[id]/page.tsx:930` es la referencia. Prohibido definir `function BackLink()` local | [OWNER] 2026-09-18 |
| T3 | **Labels de campo** con `Label`/`FormLabel` en caso normal (sin `uppercase`, sin `tracking-*`, sin `text-[11px]`) | [OWNER] 2026-09-18 |
| T4 | **Títulos de sección canónicos**: dentro de una card, `CardTitle` a secas; sección de página sin card, `h2 text-xl font-semibold`; en un formulario, `FormSection` (`components/forms/form-section.tsx`). Nada de `uppercase tracking-*` a mano. La única mayúscula permitida es la que ya trae un primitive (`StatTile`) | [OWNER] 2026-09-18 — §11 C1, C2 |
| T5 | **Sin iconos en títulos**: ni en h1/h2/h3, ni en `CardTitle`, ni en headers de `Dialog` | [OWNER] 2026-09-18 §11 C7; `context/20` §5 |
| T6 | **Sin leyendas explicativas** bajo campos ni secciones; nada técnico en pantalla. Lo único que se escribe es lo accionable cuando algo falla, en una línea | [VIGENTE] `context/14` Regla #8 "Nada técnico en pantalla…" (owner 2026-09-16) |
| T7 | **Vacío**: `EmptyState` solo para **página o listado vacío**. El vacío de una **sub-sección** (una card de la ficha, una pestaña secundaria) es una línea compacta `text-sm text-muted-foreground` con link a la acción ("Sin direcciones. Agregar") | [OWNER] 2026-09-18 — §11 C4 |
| T8 | **Montos** con `MoneyInput`; prohibido `<Input type="number">` para dinero | [VIGENTE] `context/20` §4 "MoneyInput" + §5 |
| T9 | **Nada hardcodeado a Paraguay**: moneda, locale, país y zona salen del tenant | [VIGENTE] memoria `feedback_no_hardcodear_paraguay`; guard `lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts` |
| T10 | **Un número, una vez.** Ningún valor aparece dos veces en la misma pantalla (ej. el total arriba y otra vez al pie de la misma tabla) | [OWNER] 2026-09-18 |
| T11 | **Composición**: cada bloque es un KPI (`StatTile`), un atributo (badge o texto junto al dato o en el encabezado) o contenido (card blanca). Lo que no es ninguno de los tres sobra. Nunca un bloque entero para un solo atributo | [OWNER] 2026-09-18 |
| T12 | **Jerarquía de color**: gris (`Card variant="soft"`) = números del período; blanco = contenido (tablas, gráficos, entidades) | [VIGENTE] `context/20` changelog 2026-09-09 (`StatTile` a `components/stat-tile.tsx`) |
| T13 | **Pestañas** a ancho completo (default del primitive), con estado en `?tab=` | [VIGENTE] `context/20` changelog 2026-09-09 (`TabsList` ancho completo) |
| T14 | Primitives shadcn, nunca `<button>`/`<table>`/`<label>`/`<input>` nativos | [VIGENTE] `context/20` §5 |
| T15 | Sin rubro asumido en el copy ("usuario", no "mozo") | [VIGENTE] `context/20` §1 principio 6 |
| T16 | **Íconos en pestañas**: las pestañas son navegación; ícono permitido en `TabsTrigger`, en TODAS las pestañas de un `TabsList` o en NINGUNA | [OWNER] 2026-09-18 §11 C8 |
| T17 | **Subtítulo bajo el h1 = solo DATO** (RUC, puesto, contraparte, fecha), nunca una leyenda que explique la pantalla | [OWNER] 2026-09-18 §11 C9 |
| T18 | **Listas embebidas**: menos de 10 filas = `divide-y`; desde 10, `DataTable` | [OWNER] 2026-09-18 §11 C3 |

---

## 3. Ficha de entidad

### Cuándo aplica

Una entidad con identidad propia que el usuario consulta y edita: artículo,
contacto (cliente/proveedor), persona del equipo, sucursal, lista de precios.
Ruta típica `/<entidad>/[id]`.

### Estructura canónica — [OWNER] 2026-09-18, transcripta tal cual

```
BackLink (canónico, T2)
Encabezado:  h1 text-2xl font-semibold  ·  estado  ·  acciones
Pestañas (ancho completo):  Resumen → Datos → pestañas propias…
```

1. **Armazón compartido**: encabezado (h1 `text-2xl font-semibold` + estado +
   acciones), BackLink canónico con hover de fondo y pestañas a ancho completo.
2. **Orden fijo: Resumen → Datos → pestañas propias.** Resumen primero, Datos
   segunda, siempre. Las propias van después (Stock, Variantes, Direcciones,
   Cajas, Asistencia…).
3. **"Datos" es el único lugar de edición y siempre se llama así.** No
   "Perfil", "Información", "General", "Sucursal", "Configuración".
4. **"Guardar" solo en Datos.**
5. **Resumen** = KPIs en `StatTile`/`StatsRow` + contenido en cards blancas
   con `CardTitle`.
6. **`KpiCard` muere** (`components/domain/contacts/kpi-card.tsx`): todo lo
   que muestra pasa a `StatTile`.
7. **Composición** (T11): cada bloque es un KPI, un atributo o contenido;
   nunca un bloque entero para un atributo; nunca el mismo número dos veces.

### Detalle operativo — [PROPUESTA]

- **Alta** (`/<entidad>/new` o `[id]=new`): el mismo armazón, solo con la
  pestaña **Datos** activa. Las demás pestañas se ven deshabilitadas hasta que
  la entidad existe (patrón que `items/[id]` ya usa con `disabled={isNew}`),
  así las pestañas no cambian de lugar entre alta y edición.
- **Colecciones hijas** (depósitos y cajas de una sucursal, direcciones de un
  cliente, variantes de un artículo) viven en su pestaña propia y se editan
  fila por fila con `Dialog` + `RowActions`. Eso no es "editar la ficha": el
  "Guardar" de la regla 4 es el de los atributos de la entidad. *Esto es una
  interpretación: confirmar con el owner.*
- **Formularios anexos a la entidad** (horario y rostro de una persona,
  disponibilidad de un artículo): son **secciones dentro de Datos**
  (`FormSection` + `FormSectionColumns`), no pestañas con su propio Guardar.
  Hoy `employees/[id]` muestra Guardar en Horario y Rostro (`FORM_TABS`,
  línea 75).
- **Acciones del encabezado**: primero las neutras (imprimir, duplicar),
  después las de estado; las destructivas van al final y siempre con
  `AlertDialog` (`context/20` §6 "Confirmación destructiva").
- **Estado** en el encabezado como `Badge`, junto al h1. Es un atributo:
  nunca una card propia.

### Componentes

| Pieza | Componente | Estado |
|---|---|---|
| Armazón | `EntityShell` en `components/page/entity-shell.tsx` — props **obligatorias** `summary` y `data`, más `extraTabs` | **a crear** |
| Volver | `BackLink` en `components/page/back-link.tsx` | **a crear** (forma: `transactions/[id]/page.tsx:930`) |
| KPIs del Resumen | `StatsRow`/`StatTile` — `components/stat-tile.tsx` | existe |
| Secciones de Datos | `FormSection`/`FormSectionColumns` — `components/forms/form-section.tsx` | existe |

La API es el enforcement: sin `summary` y `data` no compila, y las pestañas
propias solo entran por `extraTabs`, así que el orden y los nombres de Resumen
y Datos los fija el componente. El h1, el BackLink y el TabsList no se pasan
por props de estilo.

```tsx
<EntityShell
  back={{ href: "/employees", label: "Volver a Equipo" }}
  title={name}
  status={<Badge>Activo</Badge>}
  actions={<EmployeeHeaderActions … />}
  summary={<EmployeeSummary … />}            // obligatorio → pestaña "Resumen"
  data={<EmployeeDataForm … />}              // obligatorio → pestaña "Datos" (+ Guardar)
  extraTabs={[{ key: "asistencia", label: "Asistencia", content: … }]}
/>
```

### Prohibido

- Pestaña de edición con otro nombre que "Datos", o en otra posición que la 2ª.
- "Guardar" fuera de Datos o global en el encabezado.
- `KpiCard`, o cualquier tile de KPI hecho a mano (`div` con borde + label + número).
- `function BackLink()` local.
- Labels de campo en mayúsculas a mano (`text-[11px] uppercase tracking-wide`, como `items/[id]`, líneas 859-1090).
- Una card para un solo atributo, o el mismo número dos veces.

### Referencia

La más cercana hoy es **`employees/[id]`**: orden Resumen → Datos correcto y
"Datos" con ese nombre. Le falta el armazón compartido, tiene su propio
BackLink y muestra Guardar fuera de Datos. Ninguna ficha cumple entera (§13).

---

## 4. Reporte

### Cuándo aplica

Lectura de datos agregados: un período (rango de fechas) o una foto a hoy
(stock, balance, cuentas abiertas). Vive en `/reports/*`. Se entra desde el
índice `/reports` (Tablero, §8).

### Referencias "bien hechas" (relevamiento 2026-09-18)

1. **`components/reports/ranking-report-page.tsx`** — el único armazón de
   reporte que existe (lo usan Medios de pago y las pestañas de Artículos).
   Orden correcto: encabezado → error → `StatsRow` con `emphasis` en el KPI
   principal → gráfico → `DataTable` con export y `EmptyState`. Trae
   `embeddedRange` para usarlo dentro de pestañas (`context/20` changelog
   2026-09-10).
2. **Ventas** (`reports/sales` + `components/domain/reports/sales/sales-dashboard-tab.tsx`)
   — el único uso completo de la **comparación contra el período anterior**
   (`shiftRangeBackwards` + `pctDelta` de `lib/reports/previous-range.ts`,
   `delta` con `higherIsBetter` en `StatTile`). Ojo: pone el gráfico antes que
   los KPIs, y ahí se desvía.
3. **Anulaciones de comanda** (`reports/order-cancellations`) — el reporte de
   una página sin pestañas mejor armado: período a la derecha del encabezado,
   filtro de sucursal en el `filtersSlot` del `DataTable`, `StatsRow`,
   `DataTable` con export y `EmptyState`.

### Estructura canónica — [PROPUESTA] sobre lo que ya hacen los mejores

```
BackLink "Volver a reportes"
Encabezado: h1 + subtítulo  |  a la derecha: [Sucursal] [Período]
(Error, si lo hay)
[Pestañas: Dashboard → cortes de detalle]        ← opcional
Fila de KPIs (StatsRow/StatTile, gris) con delta vs período anterior
Gráfico principal (card blanca)
Tabla de detalle (DataTable con export)
```

1. **Encabezado**: `flex flex-col gap-3 sm:flex-row sm:items-end
   sm:justify-between`. Izquierda: BackLink, h1, subtítulo de una línea.
   Derecha: los filtros que acotan el reporte **entero**.
2. **Filtros, siempre en el mismo lugar**:
   - **Período** a la derecha del encabezado, con `DateRangePicker` +
     `useDateRange()`. [VIGENTE] el guard `lib/__tests__/date-range-global.test.ts`
     ya exige `useDateRange` en todo el que renderice `DateRangePicker`.
   - **Sucursal**: si acota el reporte entero (KPIs + gráfico + tabla), va a
     la izquierda del período, en el mismo lugar. El alcance por defecto sale
     del view-scope del usuario (`context/25`). Si solo acota la tabla, va en
     el `filtersSlot` del `DataTable` ([VIGENTE] `context/14` Regla #3
     "Filtros de dominio").
   - Cualquier otro filtro de dominio va en `filtersSlot`, nunca suelto en el
     encabezado ni en la toolbar de la tabla (hoy `purchases` pone el período
     en el `toolbarSlot`).
   - **Foto a hoy** (stock, balance, giftcards, recurrentes, cuentas
     abiertas): sin período ni delta, a propósito. El reporte se registra como
     `snapshot` (§10.3).
3. **Pestañas** (opcional): la primera se llama **Dashboard** y lleva KPIs +
   gráfico; las siguientes son cortes de detalle. El rango se toma de arriba y
   se pasa hacia abajo, nunca un segundo `useDateRange()` (`context/20`
   changelog 2026-09-10).
4. **KPIs**: `StatsRow`/`StatTile`, **con `delta` contra el período anterior
   en todo reporte de período** (`lib/reports/previous-range.ts`). El KPI
   principal lleva `emphasis`. Gris = números del período (T12). Hoy solo
   Ventas y Órdenes muestran delta.
5. **Gráfico principal**: uno, en card blanca con `CardTitle`. Recharts vía
   `components/ui/chart` (`ChartContainer`). Reusables:
   `components/domain/reports/ranking-bar-chart.tsx`,
   `composition-donut-chart.tsx`.
6. **Tabla de detalle**: `DataTable` con `tableId`, `exportFileName` y
   `emptyMessage={<EmptyState …/>}`. Una `<Table>` sin DataTable solo para
   listas cortas y fijas (`context/14` Regla #3).
7. **Error**: un solo componente (hoy cada página copia el mismo `div`
   destructivo con `AlertCircle`).

### Componentes

| Pieza | Componente | Estado |
|---|---|---|
| Armazón | `ReportShell` en `components/reports/report-shell.tsx` — props `title`, `mode: "period" \| "snapshot"`, `filters?`, `kpis`, `chart?`, `detail`; `RankingReportPage` pasa a usarlo por dentro | **a crear** (generaliza `ranking-report-page.tsx`) |
| KPIs | `StatsRow`/`StatTile` + `delta` | existe (`components/stat-tile.tsx`) |
| Delta | `shiftRangeBackwards`/`pctDelta` | existe (`lib/reports/previous-range.ts`) |
| Período | `DateRangePicker` + `useDateRange` | existe (`components/date-range-picker.tsx`, `hooks/use-date-range.ts`) |
| Detalle | `DataTable` | existe (`components/data-table/data-table.tsx`) |

Con `mode: "period"` el armazón exige el rango y pinta el picker en su lugar.
Con `mode: "snapshot"` no lo acepta.

### Prohibido

- KPIs hechos a mano (`Card` + `CardTitle text-sm` + `p text-2xl`), `KpiCard`, tiles propios.
- Gráfico antes de los KPIs.
- El período en otro lugar que la derecha del encabezado.
- Botón de export deshabilitado con TODO (`sales-dashboard-tab.tsx:587`): si no exporta, no hay botón.
- `CardTitle` o cabeceras de tabla en mayúsculas a mano (customers-dashboard-tab:312/431/561, cashflow:167).

---

## 5. Listado

### Cuándo aplica

La colección de una entidad o de un documento: buscar, filtrar, exportar y
entrar a la ficha o al documento.

### Estructura canónica

```
Encabezado: h1 (+ subtítulo)  |  a la derecha: acción primaria ("Nuevo …")
DataTable: buscador · Columnas · Excel  |  panel de filtros (filtersSlot)
Filas → click abre la ficha/documento; acciones de fila en RowActions
Vacío → EmptyState
```

1. **`DataTable`** con search, sort, date-range si aplica, export y
   column-toggle. [VIGENTE] `context/14` Regla #3; `context/20` §4
   "DataTable"; memoria `feedback_data_tables_convention`.
2. **Filtros de dominio** en `filtersSlot` con `activeFilterCount` y
   `onClearFilters`. En la barra quedan solo buscador, Columnas y Excel.
   [VIGENTE] `context/14` Regla #3 (2026-08-28).
3. **Acciones de fila** con `RowActions`, texto solo, sin "Marcar como",
   destructivas al final; con una sola acción queda un botón directo.
   [VIGENTE] `context/20` §4 "RowActions" + changelog 2026-08-08.
4. **Vacío**: `EmptyState` (listado vacío = el caso para el que existe).
   [VIGENTE] `context/20` §4 "EmptyState".
5. **Entrar a la ficha**: click en la fila (`onRowClick` → `router.push`).
   Es lo que ya hacen los 10 listados principales. No se agrega columna
   "Ver" ni ítem "Abrir" en RowActions. [PROPUESTA — fija lo que ya existe]
6. **Acción primaria** ("Nuevo artículo", "Nueva remisión") arriba a la
   derecha del encabezado. [PROPUESTA]
7. **Sin KPIs arriba del listado.** Si hacen falta números, es un Reporte o
   el Resumen de la ficha. [OWNER] 2026-09-18, §11 C6
8. Listas cortas embebidas en una ficha o un modal no son este arquetipo
   (`divide-y` bajo 10 filas — T18, §11 C3).

### Componentes

`DataTable` (`components/data-table/data-table.tsx`), `RowActions`
(`components/data-table/row-actions.tsx`), `EmptyState`
(`components/empty-state.tsx`), encabezado con `PageHeader`
(`components/page/page-header.tsx`, **a crear**, el mismo que usan Reporte y
Ajustes).

### Referencia

`/remisiones` y `/stock-transfer`: los más chicos y los más limpios (h1,
DataTable, EmptyState, `onRowClick`). En Ajustes, `/settings/roles` y
`/settings/sessions` (con RowActions).

---

## 6. Documento

### Cuándo aplica

Un comprobante u operación con número, estado, líneas y totales: transacción
(venta, cotización, recibo), orden, compra, borrador de compra, orden de pago,
remisión, transferencia, ajuste de stock, conteo de inventario y lote de
producción. Tiene dos modos: **vista** (`/<doc>/[id]`) y **editor**
(`/<doc>/new`, `/<doc>/[id]/editar`).

### Base vigente — [VIGENTE] `context/20` changelog 2026-06-24 "Redesign invoice-style de detalle de transacciones", transcripta

- Sin stat cards.
- Tabla de ítems canónica.
- Totales alineados a la derecha.
- ~~Estado final al pie.~~ **Superseded 2026-09-18 (§11 C5): el estado va en el encabezado, junto al h1**, igual que en la ficha.
- Sin badge de tipo arriba.

(Se llegó en 3 iteraciones: stat cards → stat cards con otro estilo → patrón
de factura.)

### Estructura canónica completa — [PROPUESTA]

```
BackLink "Volver a <listado>"
Encabezado: h1 = tipo de documento + número · estado (Badge)   |   acciones (Imprimir, de estado…, destructivas al final)
            línea secundaria: contraparte (cliente/proveedor) · fecha
Datos generales  (card blanca, filas label → valor; atributos, no bloques)
Líneas           (card con Table: ítem · cantidad · precio · total)
Totales          (a la derecha, pegados a las líneas: subtotal, impuestos, descuento, TOTAL una sola vez)
Pagos / Documentos relacionados  (cards, solo si hay)
Línea de tiempo / auditoría      (al final, si el documento la tiene)
```

1. **El número es el título.** El h1 es "Orden #123", "Remisión 001-001-0000045",
   no el nombre genérico ("Transferencia de stock", "Conteo de inventario").
2. **El total aparece una vez**, al pie de las líneas (T10). Hoy
   `orders/[id]` lo muestra en el encabezado (línea 129) y otra vez al pie de
   la tabla (línea 220).
3. **La nota y los atributos sueltos** (condición, vencimiento, depósito
   origen) son filas de "Datos generales", no cards propias (T11). Hoy
   `transactions/[id]` tiene una card "Nota" y los editores de remisión y
   transferencia una card "Nota (opcional)".
4. **Líneas** con `<Table>` shadcn: una tabla de líneas es una lista acotada
   dentro del documento, no un listado, así que no lleva DataTable.
5. **Editor**: las mismas zonas y en el mismo orden que la vista, con campos
   en el lugar del valor. Montos con `MoneyInput` (T8). Cantidades con
   `Input` numérico. La acción de confirmar va arriba a la derecha, nombrada
   con el verbo del documento ("Confirmar compra", "Emitir remisión"), nunca
   "Guardar" genérico. Es la misma posición que las acciones de la vista.
6. **Acciones fiscales o irreversibles** (anular, emitir): siempre
   `AlertDialog`. Si están bloqueadas, botón deshabilitado + tooltip con el
   motivo, nunca una banda (memoria `feedback_pos_alerts_on_the_action_not_banners`,
   aplicada en `transactions/[id]` "Anular venta").
7. **Métricas operativas del documento** (tiempos de preparación de una
   orden): no son stat cards arriba. Van dentro de la línea de tiempo o en
   el Reporte correspondiente (Órdenes). Hoy `orders/[id]:315-318` usa
   `StatTile`.

### Componentes

`DocumentShell` en `components/page/document-shell.tsx` (**a crear**):
props `back`, `title` (tipo + número), `counterparty`, `actions`, `general`
(filas), `lines`, `totals`, `related?`, `timeline?`. Usa el mismo `BackLink`
y el mismo encabezado que la ficha. Filas label → valor: se extrae el
`InfoRow` de `transactions/[id]/page.tsx:959` a `components/page/info-row.tsx`.

### Referencia

**`transactions/[id]`** es la base del patrón (contexto: `context/39`), y
también la que más reglas nuevas rompe: badge de tipo en el h1, `InfoCard`
con `CardTitle` en mayúsculas a mano (línea 950), card "Nota" para un solo
atributo. **`purchase/[id]`** tiene la mejor separación de líneas y totales.

---

## 7. Ajustes

### Cuándo aplica

La configuración del comercio y de sus catálogos.

### Estructura canónica

1. **Un solo lugar**: `/settings`, un shell a pantalla completa con menú de
   secciones a la izquierda (`/settings?section=<id>`). El menú sale de
   `SETTINGS_SECTIONS` en `lib/settings/sections.ts`. Una sección es un
   formulario dentro del shell o, con `href`, una página propia con el mismo
   encabezado. [VIGENTE] código actual; `context/20` §4 (el shell de
   `settings/page.tsx` es excepción documentada de padding).
2. **Formularios de sección**: `FormSection` + `FormSectionColumns`
   (`context/20` changelog 2026-09-09). "Guardar" solo en secciones de
   formulario (`FORM_SECTIONS`), igual que en la regla de Datos de la ficha.
3. **Catálogos en Ajustes → Catálogo** (`/settings/catalog?tab=…`), una
   pestaña por catálogo (categorías, marcas, etiquetas, impuestos, medios de
   pago, motivos de merma, bolsillos), cada una con `CatalogManager`
   (`components/catalog/catalog-manager.tsx`). [VIGENTE] `context/20` §4
   "CatalogManager"; bolsillos del wallet como caso reciente (`context/74`).
4. **Módulos nuevos se ACOPLAN** a secciones existentes: pestaña de
   Catálogo, sección de Ajustes, pestaña de la ficha que corresponda. Nunca
   una entrada de menú aislada sin justificación escrita. [OWNER] memoria
   `feedback_integrar_en_secciones_existentes`.
5. **Una función, un lugar.** Si una configuración aparece como sección de
   Ajustes Y como página suelta, sobra una. [PROPUESTA] Hoy pasa con
   Módulos (`/modules` y `?section=modules`), Integraciones (`/integraciones`
   y `?section=integraciones`) y Mi plan (`/history-billing` y
   `?section=plan`, oculta).
6. **Pantallas de Ajustes que son listados** (roles, sesiones, dispositivos,
   keys, listas de precios, cierre de período) siguen el arquetipo Listado
   (§5) dentro de Ajustes. Una lista de precios abierta sigue el arquetipo
   Ficha (§3).

### Prohibido

- Un catálogo fuera de Ajustes → Catálogo sin motivo escrito. Hoy
  `/finanzas/configuracion` tiene sus propias pestañas de catálogo
  (Categorías, Centros de costo) — ver §13.
- Editar el mismo atributo desde dos pantallas: la cuenta de cada medio de
  pago se edita en Ajustes → Catálogo (`settings/catalog/page.tsx:419-428`)
  y en Finanzas → Configuración → Medios de pago.

---

## 8. Tablero

### Cuándo aplica

La portada de un área: `/` (dashboard), `/finanzas` (resumen) y `/reports`
(índice).

### Estructura — [PROPUESTA]

1. Encabezado estándar (h1 + subtítulo; período a la derecha si los números
   son de un período).
2. **Números**: `StatTile` (gris). Cuando la cantidad de tiles es variable
   (saldos por cuenta + totales), va en grilla y no en `StatsRow` ([VIGENTE]
   `context/20` changelog 2026-09-09, dos reglas del owner: un card no ocupa
   los 3 espacios y no se deja hueco sin sentido).
3. **Contenido y accesos**: cards blancas. Un índice (hub) es una grilla de
   accesos con título + una línea de qué responde, agrupados con un título de
   grupo canónico. `reports/page.tsx` es la referencia.
4. Sin detalle propio: la tabla completa vive en el Listado o el Reporte al
   que el tablero lleva.

---

## 9. Herramienta

Chat del asistente, códigos de barras, editor de plantillas de impresión,
editor de espacios, conciliación de finanzas. Estructura libre según la
interacción, **pero aplican todas las reglas transversales (§2)**, empezando
por el h1 canónico (hoy `/items/barcodes` no tiene h1). Registrarla como
herramienta exige una línea que diga qué interacción no cubre ningún otro
arquetipo (§10.3).

---

## 10. Enforcement: componentes y guards

### 10.1 Componentes compartidos (la API impone la estructura)

| Componente | Path | Arquetipos | Estado |
|---|---|---|---|
| `PageHeader` (h1 + subtítulo + slot derecho) | `components/page/page-header.tsx` | todos | a crear |
| `BackLink` | `components/page/back-link.tsx` | Ficha, Documento, Reporte | a crear |
| `EntityShell` (`summary` y `data` obligatorios + `extraTabs`) | `components/page/entity-shell.tsx` | Ficha | a crear |
| `ReportShell` (`mode: period \| snapshot`) | `components/reports/report-shell.tsx` | Reporte | a crear (sale de `ranking-report-page.tsx`) |
| `DocumentShell` + `InfoRow` | `components/page/document-shell.tsx`, `info-row.tsx` | Documento | a crear |
| `StatsRow`/`StatTile` | `components/stat-tile.tsx` | Ficha, Reporte, Tablero | existe |
| `DataTable`/`RowActions` | `components/data-table/` | Listado, Reporte | existe |
| `EmptyState` | `components/empty-state.tsx` | Listado, página vacía | existe |
| `FormSection`/`FormSectionColumns` | `components/forms/form-section.tsx` | Ficha (Datos), Ajustes | existe |
| `CatalogManager` | `components/catalog/catalog-manager.tsx` | Ajustes → Catálogo | existe |

### 10.2 Guards de CI (vitest) — patrón existente

Mismo patrón que `lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts`
y `lib/navigation/__tests__/routes-coverage.test.ts`: se recorre el árbol, se
buscan patrones, y cada excepción vive en una allowlist **con motivo
escrito**. Los asserts son simétricos: un hit nuevo rompe el test y una
entrada de allowlist que ya no hace falta también (para que la excusa no
sobreviva a su propio fix).

Archivo propuesto: `frontend/lib/ui/__tests__/screen-archetypes.test.ts`.

| Guard | Qué rompe el test |
|---|---|
| G1 registro | Una `page.tsx` bajo `app/(panel)` que no está en `SCREEN_ARCHETYPES` (§10.3), o una entrada del registro que apunta a una ruta que ya no existe |
| G2 armazón | Una página registrada como `ficha` que no importa `EntityShell`; `reporte` sin `ReportShell`; `documento` sin `DocumentShell`; `listado` sin `DataTable` |
| G3 KpiCard | Cualquier import de `kpi-card` (y el archivo borrado) |
| G4 BackLink local | `function BackLink` / `function BackButton` / `<ArrowLeft` dentro de un `Link` fuera de `components/page/back-link.tsx` |
| G5 mayúsculas a mano | `uppercase` junto a `tracking-` en un `className` bajo `app/(panel)` y `components/domain` (los primitives como `StatTile` quedan fuera por path) |
| G6 pestaña de edición | En una ficha, un `label` de pestaña de edición distinto de "Datos" (lo impide la API de `EntityShell`; el guard cubre fichas que todavía no migraron, vía allowlist con fecha) |
| existente | `date-range-global.test.ts` (período vía `useDateRange`), `no-hardcoded-paraguay.test.ts` (T9) |

Iconos en headings (T5) y `type="number"` en montos (T8) no se pueden
detectar bien por regex sin falsos positivos. Quedan para review
(`context/14` checklist) hasta que haya un chequeo por AST.

### 10.3 Registro de arquetipos

`frontend/lib/ui/screen-archetypes.ts` (**a crear**):
`Record<ruta, { archetype: "ficha" | "reporte" | "listado" | "documento" |
"ajustes" | "tablero" | "herramienta" | "redirect"; mode?: "period" |
"snapshot"; reason?: string }>`. `reason` es obligatorio para `herramienta`.
Es a la vez el inventario de §13 en forma ejecutable: la pasada se mide
contra este archivo, no contra una tabla en markdown que se pudre.

---

## 11. Contradicciones resueltas (owner 2026-09-18)

Las nueve se señalaron en la primera versión de este doc y el owner las
resolvió en bloque el mismo día. `context/14` y `context/20` ya están
alineados (changelog de `context/20`, 2026-09-18).

| # | Qué chocaba | Resolución |
|---|---|---|
| C1 | `context/14` Regla #1 canonizaba el "Label uppercase de bloque" (`uppercase tracking-wider`) | **Mayúsculas a mano PROHIBIDAS.** La fila quedó superseded en `context/14`. Solo la mayúscula que trae un primitive (`StatTile`) |
| C2 | Título de sección: h3 en `context/14`, h2 en `context/20` | **Dentro de una card: `CardTitle` canónico. `h2 text-xl font-semibold` solo para secciones de PÁGINA sin card.** `FormSection` (h3) queda para subsecciones de formulario |
| C3 | Lista corta embebida: botones/`Table` en 14, `divide-y` en 20; umbrales distintos | **Menos de 10 filas embebidas = `divide-y`; desde 10 filas, `DataTable`.** 14 y 20 con el mismo umbral |
| C4 | "Empty state siempre con `EmptyState`" vs la línea compacta | **`EmptyState` solo para página o listado vacío; sub-sección vacía = línea compacta con link** |
| C5 | Documento tipo factura con "status final al pie" vs encabezado de ficha y código | **El estado va en el ENCABEZADO, junto al h1.** Esa parte del changelog 2026-06-24 de 20 quedó superseded |
| C6 | "Sin stat cards arriba de listados" superseded solo para reportes | **Sigue vigente para LISTADOS: sin stat cards arriba; los números van al reporte** |
| C7 | `size-5 (header)` en 14 Regla #6 vs prohibición de íconos en títulos | **Sin íconos en títulos** (h1/h2/h3, `CardTitle`, headers de `Dialog`). El `size-5 (header)` se borró |
| C8 | "Iconos solo en navegación" vs íconos en `TabsTrigger` | **Las pestañas SON navegación: ícono permitido, en TODAS las pestañas de un `TabsList` o en NINGUNA** |
| C9 | Subtítulo "Descripción opcional" bajo el h1 vs regla de no leyendas | **El subtítulo es solo DATO** (RUC, puesto, contraparte), nunca leyenda explicativa. Patrón de `context/20` §6 corregido |

---

## 12. Checklist de auditoría

Para la pasada por todos los sectores. Cada ítem se responde sí/no mirando la
pantalla y el código.

### Transversal (toda pantalla)

- [ ] ¿Está registrada en `SCREEN_ARCHETYPES` con un solo arquetipo?
- [ ] ¿Tiene exactamente un h1 `text-2xl font-semibold`, pintado por el armazón?
- [ ] ¿Usa el `BackLink` compartido (si tiene "volver")?
- [ ] ¿Cero `uppercase tracking-*` a mano?
- [ ] ¿Cero iconos en h1/h2/h3, CardTitle y headers de Dialog?
- [ ] ¿Cero leyendas explicativas bajo campos o secciones?
- [ ] ¿`EmptyState` solo en página/listado vacío, y línea compacta con link en sub-secciones?
- [ ] ¿Cada bloque es KPI, atributo o contenido? ¿Ningún bloque para un solo atributo?
- [ ] ¿Ningún número aparece dos veces?
- [ ] ¿Montos con `MoneyInput`? ¿Nada fijado a Paraguay?
- [ ] ¿Gris solo para números del período, blanco para contenido?
- [ ] ¿Subtítulo bajo el h1 es solo dato, no leyenda?
- [ ] ¿Íconos de pestañas en todas o en ninguna?
- [ ] ¿Títulos: `CardTitle` dentro de card, `h2 text-xl` en sección de página sin card?
- [ ] ¿Listas embebidas de menos de 10 filas en `divide-y`?

### Ficha

- [ ] ¿Usa `EntityShell`?
- [ ] ¿La 1ª pestaña es "Resumen" y la 2ª es "Datos"?
- [ ] ¿"Datos" es el único lugar donde se editan los atributos de la entidad?
- [ ] ¿"Guardar" aparece solo en Datos?
- [ ] ¿KPIs del Resumen en `StatTile`/`StatsRow`? ¿Cero `KpiCard`?
- [ ] ¿El contenido del Resumen está en cards blancas con `CardTitle`?
- [ ] ¿El estado es un badge en el encabezado?
- [ ] ¿Las colecciones hijas están en pestañas propias, editadas por fila?
- [ ] ¿En el alta, las pestañas están en el mismo lugar (deshabilitadas)?

### Reporte

- [ ] ¿Usa `ReportShell` con `mode` correcto (period/snapshot)?
- [ ] ¿Período a la derecha del encabezado vía `useDateRange`?
- [ ] ¿Sucursal (si acota todo) al lado del período; filtros de detalle en `filtersSlot`?
- [ ] ¿Orden KPIs → gráfico → tabla?
- [ ] ¿KPIs en `StatsRow` con `delta` vs período anterior (si es de período)?
- [ ] ¿Un gráfico principal en card blanca?
- [ ] ¿Detalle en `DataTable` con export que funciona?
- [ ] ¿Con pestañas, la primera es "Dashboard" y el rango viene de arriba?

### Listado

- [ ] ¿`DataTable` con buscador, Columnas y Excel en la barra?
- [ ] ¿Filtros de dominio en `filtersSlot` con `activeFilterCount`?
- [ ] ¿Click en la fila abre la ficha/documento?
- [ ] ¿Acciones de fila en `RowActions`, texto solo?
- [ ] ¿`EmptyState` en vacío?
- [ ] ¿Acción primaria arriba a la derecha? ¿Sin KPIs arriba?

### Documento

- [ ] ¿Usa `DocumentShell`?
- [ ] ¿El h1 es tipo + número?
- [ ] ¿Datos generales como filas, sin cards de un solo atributo?
- [ ] ¿Líneas en `Table` y totales a la derecha, con el total una sola vez?
- [ ] ¿Acciones: neutras → de estado → destructivas, las irreversibles con `AlertDialog` y bloqueo con tooltip?
- [ ] ¿El editor tiene las mismas zonas y en el mismo orden que la vista?
- [ ] ¿Sin stat cards? ¿Estado como badge junto al h1?

### Ajustes

- [ ] ¿Vive como sección de `/settings` (o página con `href` desde `SETTINGS_SECTIONS`)?
- [ ] ¿Es un catálogo? ¿Está en Ajustes → Catálogo con `CatalogManager`?
- [ ] ¿La misma configuración NO existe también en otra pantalla?
- [ ] ¿Si es un módulo nuevo, se acopló a una sección existente?
- [ ] ¿Formularios con `FormSection` y Guardar solo en secciones de formulario?

---

## 13. Inventario de pantallas (2026-09-18)

Relevado recorriendo las 85 `page.tsx` de `frontend/app/(panel)` (barrido por
grep de cada archivo + lectura de las fichas, de los reportes y de los
encabezados de documentos). **Cumple** = sí / parcial / no. **Parcial** =
estructura correcta, pero falla por no usar los componentes compartidos (que
todavía no existen, así que ninguna pantalla puede cumplir entera hoy) o por
una desviación menor. Los fallos comunes a todo el panel **no se repiten por
fila**: sin armazón compartido, `BackLink` copiado en 22 archivos, y en
reportes de período la falta de delta (solo Ventas y Órdenes lo tienen).
"Sin verificar" = wrapper de pocas líneas cuyo componente no se auditó a fondo.

### Fichas

| Ruta | Cumple | Qué falla |
|---|---|---|
| `items/[id]` | no | Pestañas Perfil, Imágenes, Configuración, Disponibilidad, Stock… sin Resumen ni Datos; edita en Perfil y Configuración; Guardar global; 10 labels en mayúsculas a mano; 10 `type="number"` |
| `contacts/[id]` | no | Datos es la ÚLTIMA pestaña (Resumen, Comportamiento, Financiero, …, Datos); `KpiCard` ×8; 4 mayúsculas a mano |
| `employees/[id]` | parcial | Resumen → Datos correcto; Guardar también en Horario y Rostro; mayúsculas a mano en `employee-summary-tab` (3) |
| `outlets/[id]` | no | Pestañas Sucursal, Depósitos, Cajas: sin Resumen, edita en "Sucursal", Guardar global; 3 `type="number"` |
| `settings/price-lists/[id]` | no | Sin pestañas Resumen/Datos; `type="number"` ×2; `<label>` nativo ×2; volver con `ArrowLeft size-4` propio |

### Reportes

| Ruta | Cumple | Qué falla |
|---|---|---|
| `reports/sales` | no | Gráfico antes de los KPIs; export deshabilitado con TODO; `CardTitle` en mayúsculas (dashboard-tab:587) |
| `reports/orders` | parcial | Estructura y delta ok |
| `reports/products` | parcial | Sin delta; `ChartCard` local |
| `reports/payment-methods` | parcial | Usa `RankingReportPage` (referencia); sin delta |
| `reports/customers` | no | 3 mayúsculas a mano (customers-dashboard-tab); BackLink `text-sm` distinto |
| `reports/finance-breakdown` | parcial | `TabsContent mt-4` en vez de `gap-4`; sin delta |
| `reports/users` | parcial | Tabla de comisiones con `<Table>` suelta; sin delta |
| `reports/attendance` | parcial | Sin delta |
| `reports/production` | parcial | Sin delta |
| `reports/open-invoices` | parcial | Foto a hoy; encabezado sin slot derecho |
| `reports/drawers` | parcial | Sin gráfico ni delta; `drawer-detail-modal` con 5 mayúsculas |
| `reports/expenses` | parcial | Sin gráfico ni delta |
| `reports/audit` | no | Sin KPIs: es un listado de auditoría → reclasificar como Listado |
| `reports/inventory` | no | Sin KPIs: movimientos del ledger → reclasificar como Listado o sumar KPIs |
| `reports/order-cancellations` | parcial | Referencia; sin delta |
| `reports/purchases` | no | Período en la toolbar de la tabla; sin KPIs; raíz `gap-4` |
| `reports/stock` | no | Foto a hoy ok; aviso propio "Seleccioná una sucursal" en vez del patrón |
| `reports/giftcards` | parcial | Foto a hoy; encabezado sin slot derecho |
| `reports/recurring` | parcial | Foto a hoy; encabezado sin slot derecho |
| `reports/balance` | no | Encabezado apilado; volver con otro estilo |
| `reports/cashflow` | no | `<table>` a mano en card; cabecera en mayúsculas (l.167); volver con otro estilo |
| `reports/summary-year` | parcial | Año como selector (ok para anual); volver solo `w-fit` |
| `finanzas/prevision` | parcial | Período hacia adelante (allowlist); `<Table>` suelta |

### Listados

| Ruta | Cumple | Qué falla |
|---|---|---|
| `contacts` | sí | — |
| `employees` | sí | — |
| `items` | parcial | 2 `<button>` nativos; 1 `type="number"` |
| `inventory-count` | sí | — |
| `ordenes-pago` | sí | — |
| `outlets` | sí | — |
| `purchase/drafts` | sí | — |
| `remisiones` | sí | Referencia |
| `stock-transfer` | sí | Referencia |
| `produccion` | parcial | Dos DataTable en pestañas (sin verificar que cada una sea listado y no reporte) |
| `reposicion` | parcial | `<Table>` suelta junto al DataTable |
| `finanzas/movimientos` | parcial | h1 propio dentro del layout de Finanzas (dos h1 con "Finanzas") |
| `finanzas/cheques` | sí | — |
| `finanzas/creditos` | parcial | `<Table>` suelta junto al DataTable |
| `finanzas/cuentas` | no | Sin DataTable (cards por cuenta) |
| `notificaciones` | no | `<Table>` y `<button>` nativos, sin DataTable |
| `settings/roles` | sí | Referencia en Ajustes |
| `settings/sessions` | sí | — |
| `settings/api-keys` | sí | — |
| `settings/devices` | sí | — |
| `settings/cierre-de-periodo` | sí | — |
| `settings/price-lists` | parcial | 1 `<button>` nativo; 1 `type="number"` |

### Documentos

| Ruta | Cumple | Qué falla |
|---|---|---|
| `transactions/[id]` | no | Badge de tipo en el h1 (contra 2026-06-24); `InfoCard` con `CardTitle` en mayúsculas (l.950); card "Nota" para un atributo; 4 mayúsculas |
| `orders/[id]` | no | Total dos veces (l.129 y l.220); `StatTile` de tiempos arriba; BackLink local |
| `purchase/[id]` | no | `InfoCard` con `CardTitle` en mayúsculas (l.668); 3 badges en el encabezado; 1 `type="number"` |
| `purchase/drafts/[id]` | no | BackLink local; label en mayúsculas (l.698); h1 "Revisar factura" sin número |
| `ordenes-pago/[id]` | parcial | Volver con `ArrowLeft h-4` propio; total en el encabezado (verificar duplicado) |
| `remisiones/[id]` | parcial | Volver propio |
| `stock-transfer/[id]` | parcial | Volver propio; h1 genérico sin número |
| `inventory-count/[id]` | parcial | Volver propio; h1 genérico sin número |
| `purchase` (nueva compra) | no | Label en mayúsculas (l.557) |
| `remisiones/new` | parcial | Card "Nota (opcional)" para un atributo; sin BackLink |
| `stock-transfer/new` | parcial | Card "Nota (opcional)"; sin BackLink |
| `stock-adjustment` | parcial | Editor sin listado ni vista propia; h1 "Ajustes de stock" en plural |
| `produccion/lote` | parcial | 1 `type="number"` (cantidad: verificar) |
| `ordenes-pago/new`, `ordenes-pago/[id]/editar` | sin verificar | Wrappers de `PaymentOrderForm` |

### Ajustes

| Ruta | Cumple | Qué falla |
|---|---|---|
| `settings` (shell + secciones) | parcial | 2 `<button>` nativos; 3 `type="number"` (verificar si alguno es monto) |
| `settings/catalog` | sí | Referencia (ícono en todas las pestañas: ok por C8) |
| `settings/facturacion-electronica` | sin verificar | Wrapper de `einvoice-manager` |
| `settings/printers` | sin verificar | Wrapper de `printers-manager` |
| `finanzas/configuracion` | no | Catálogos (Categorías, Centros de costo) fuera de Ajustes → Catálogo; la cuenta de cada medio de pago se edita acá Y en Ajustes → Catálogo |
| `modules` | no | Duplica `/settings?section=modules` |
| `integraciones` | no | Duplica `/settings?section=integraciones` |
| `history-billing` | no | Duplica `/settings?section=plan` |

### Tableros

| Ruta | Cumple | Qué falla |
|---|---|---|
| `/` (dashboard) | no | 9 mayúsculas a mano; `<Table>` suelta; 1 `type="number"` |
| `finanzas` (resumen) | parcial | `StatTile` en grilla (correcto por 2026-09-09); `<Table>` suelta |
| `reports` (índice) | sí | Referencia de hub |

### Herramientas

| Ruta | Cumple | Qué falla |
|---|---|---|
| `chat` | parcial | 1 `<button>` nativo |
| `items/barcodes` | no | Sin h1; `<label>` nativo |
| `settings/print-templates` | sin verificar | Editor (canvas) |
| `settings/espacios` | sin verificar | Editor de plano |
| `finanzas/conciliacion` | sin verificar | `StatTile` en bloque sticky (correcto por 2026-09-09) |

### Redirects

`settings/team` → `/employees`, `finanzas/ajustes` → `/finanzas/configuracion`,
`finanzas/categorias`, `finanzas/reportes` → `/reports/finance-breakdown`.

### Resumen

| Arquetipo | Pantallas | Sí | Parcial | No | Sin verificar |
|---|---|---|---|---|---|
| Ficha | 5 | 0 | 1 | 4 | 0 |
| Reporte | 23 | 0 | 15 | 8 | 0 |
| Listado | 22 | 14 | 6 | 2 | 0 |
| Documento | 15 | 0 | 8 | 5 | 2 |
| Ajustes | 8 | 1 | 1 | 4 | 2 |
| Tablero | 3 | 1 | 1 | 1 | 0 |
| Herramienta | 5 | 0 | 1 | 1 | 3 |
| Redirect | 4 | — | — | — | — |

Total: 85 `page.tsx`. Con la pasada, cada fila de esta tabla se convierte en
una entrada de `SCREEN_ARCHETYPES` (§10.3), y desde ahí el guard mide el
cumplimiento en vez de esta tabla.
